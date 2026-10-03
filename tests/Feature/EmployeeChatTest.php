<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeScreenshotLog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 28-Sep-2026: admin ↔ employee chat + screenshot ref on the drawer timeline. */
class EmployeeChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function login(string $email): string
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'password'])->assertOk()->json('token');
    }

    private function agent(): array
    {
        $user = $this->login('priya.raman@ametecs.io');
        $dev = $this->withToken($user)->postJson('/api/agent/register-device', ['device_uuid' => 'CHAT-PC', 'computer_name' => 'CHAT'])
            ->assertCreated()->json('device_token');
        $emp = Employee::whereHas('user', fn ($q) => $q->where('email', 'priya.raman@ametecs.io'))->firstOrFail();

        return [$dev, $emp];
    }

    public function test_admin_message_reaches_the_agent_and_the_reply_comes_back(): void
    {
        [$dev, $emp] = $this->agent();
        $admin = $this->login('admin@ametecs.io');

        $this->withToken($admin)->postJson("/api/chat/{$emp->id}", ['body' => 'Hi Priya, please share the report'])->assertCreated();

        // The fast poll announces it…
        $this->withToken($dev)->getJson('/api/agent/liveview/poll?device_uuid=CHAT-PC')->assertOk()->assertJsonPath('chat_unread', 1);
        // …the agent reads it (which marks it read)…
        $this->withToken($dev)->getJson('/api/agent/chat')->assertOk()
            ->assertJsonPath('data.0.sender', 'ADMIN')->assertJsonPath('data.0.body', 'Hi Priya, please share the report');
        $this->withToken($dev)->getJson('/api/agent/liveview/poll?device_uuid=CHAT-PC')->assertJsonPath('chat_unread', 0);

        // …and replies.
        $this->withToken($dev)->postJson('/api/agent/chat', ['body' => 'Sending in 5 min'])->assertCreated();
        $thread = $this->withToken($admin)->getJson("/api/chat/{$emp->id}")->assertOk()->json('data');
        $this->assertSame(['ADMIN', 'EMPLOYEE'], array_column($thread, 'sender'));
        $this->assertTrue($thread[0]['read'], 'admin sees that the employee read it');

        // Incremental fetch.
        $this->assertCount(1, $this->withToken($admin)->getJson("/api/chat/{$emp->id}?after_id=" . $thread[0]['id'])->json('data'));
    }

    /** 29-Sep-2026 (Ejaz): chat history lives only for the agent session. */
    public function test_chat_history_is_cleared_when_the_session_ends_and_on_next_sign_in(): void
    {
        [$dev, $emp] = $this->agent();
        $admin = $this->login('admin@ametecs.io');
        $this->withToken($admin)->postJson("/api/chat/{$emp->id}", ['body' => 'before sign-out'])->assertCreated();

        $this->withToken($dev)->postJson('/api/agent/session-logout', ['device_uuid' => 'CHAT-PC'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->assertCount(0, $this->withToken($admin)->getJson("/api/chat/{$emp->id}")->json('data'));

        // Nothing from before a sign-in carries into the new session.
        $this->withToken($admin)->postJson("/api/chat/{$emp->id}", ['body' => 'while signed out'])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->agent();
        $this->app['auth']->forgetGuards();
        $this->assertCount(0, $this->withToken($admin)->getJson("/api/chat/{$emp->id}")->json('data'));
    }

    /** 29-Sep-2026 (Ejaz): closing the chat on EITHER side ends the conversation. */
    public function test_closing_the_chat_on_either_side_deletes_the_thread(): void
    {
        [$dev, $emp] = $this->agent();
        $admin = $this->login('admin@ametecs.io');
        $this->withToken($admin)->postJson("/api/chat/{$emp->id}", ['body' => 'one'])->assertCreated();
        $this->withToken($admin)->deleteJson("/api/chat/{$emp->id}")->assertOk();
        $this->assertCount(0, $this->withToken($admin)->getJson("/api/chat/{$emp->id}")->json('data'));

        $this->withToken($admin)->postJson("/api/chat/{$emp->id}", ['body' => 'two'])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->withToken($dev)->deleteJson('/api/agent/chat')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->assertCount(0, $this->withToken($admin)->getJson("/api/chat/{$emp->id}")->json('data'));
    }

    /** 30-Sep-2026 (Ejaz): agent shows who wrote it; WhatsApp ticks sent → delivered → read. */
    public function test_sender_role_and_whatsapp_ticks(): void
    {
        [$dev, $emp] = $this->agent();
        $admin = $this->login('admin@ametecs.io');
        $sent = $this->withToken($admin)->postJson("/api/chat/{$emp->id}", ['body' => 'Hello'])->assertCreated()->json('data');
        $this->assertSame('Company Admin', $sent['role']);
        $this->assertFalse($sent['delivered']);

        // ✓ sent only: nothing has reached the PC yet.
        $r = $this->withToken($admin)->getJson("/api/chat/{$emp->id}")->assertOk()->json('receipts');
        $this->assertSame(['read_upto' => 0, 'delivered_upto' => 0], $r);

        // ✓✓ grey: the agent's fast poll picked it up.
        $this->app['auth']->forgetGuards();
        $this->withToken($dev)->getJson('/api/agent/liveview/poll?device_uuid=CHAT-PC')->assertJsonPath('chat_unread', 1);
        $this->app['auth']->forgetGuards();
        $r = $this->withToken($admin)->getJson("/api/chat/{$emp->id}?after_id={$sent['id']}")->json('receipts');
        $this->assertSame(['read_upto' => 0, 'delivered_upto' => $sent['id']], $r);

        // ✓✓ blue: the chat window loaded it; the agent sees the sender's name + role.
        $this->app['auth']->forgetGuards();
        $got = $this->withToken($dev)->getJson('/api/agent/chat')->assertOk();
        $got->assertJsonPath('data.0.role', 'Company Admin')->assertJsonPath('data.0.name', 'Company Admin');
        $reply = $this->withToken($dev)->postJson('/api/agent/chat', ['body' => 'Hi'])->assertCreated()->json('data');
        $this->assertNull($reply['role']);
        $this->assertSame(0, $this->withToken($dev)->getJson('/api/agent/chat?after_id=' . $reply['id'])->json('receipts.read_upto'));
        $this->app['auth']->forgetGuards();
        $r = $this->withToken($admin)->getJson("/api/chat/{$emp->id}?after_id={$reply['id']}")->json('receipts');
        $this->assertSame(['read_upto' => $sent['id'], 'delivered_upto' => $sent['id']], $r);

        // …and the employee sees blue ticks once the admin has the reply on screen.
        $this->app['auth']->forgetGuards();
        $this->assertSame($reply['id'], $this->withToken($dev)->getJson('/api/agent/chat?after_id=' . $reply['id'])->json('receipts.read_upto'));
    }

    /** 30-Sep-2026 (Ejaz): the admin learns of a reply without the chat open; opening it clears the count. */
    public function test_unread_employee_replies_are_counted_until_the_admin_opens_the_chat(): void
    {
        [$dev, $emp] = $this->agent();
        $admin = $this->login('admin@ametecs.io');
        $this->assertSame([], $this->withToken($admin)->getJson('/api/chat-unread')->assertOk()->json('data'));

        $this->app['auth']->forgetGuards();
        $this->withToken($dev)->postJson('/api/agent/chat', ['body' => 'Sir, one question'])->assertCreated();
        $last = $this->withToken($dev)->postJson('/api/agent/chat', ['body' => 'Are you there?'])->assertCreated()->json('data.id');

        $this->app['auth']->forgetGuards();
        $u = $this->withToken($admin)->getJson('/api/chat-unread')->assertOk()->json('data');
        $this->assertCount(1, $u);
        $this->assertSame(['employee_id' => $emp->id, 'name' => $emp->fullName(), 'count' => 2, 'last_id' => $last, 'body' => 'Are you there?'], $u[0]);

        $this->withToken($admin)->getJson("/api/chat/{$emp->id}")->assertOk(); // admin opens the chat
        $this->assertSame([], $this->withToken($admin)->getJson('/api/chat-unread')->json('data'));

        $this->app['auth']->forgetGuards();
        $this->withToken($this->login('priya.raman@ametecs.io'))->getJson('/api/chat-unread')->assertForbidden();
    }

    public function test_an_employee_login_cannot_use_the_admin_chat(): void
    {
        [, $emp] = $this->agent();
        $self = $this->login('priya.raman@ametecs.io');
        $this->withToken($self)->getJson("/api/chat/{$emp->id}")->assertForbidden();
    }

    public function test_timeline_screenshot_rows_carry_the_screenshot_id(): void
    {
        [, $emp] = $this->agent();
        $shot = EmployeeScreenshotLog::withoutGlobalScopes()->create([
            'company_id' => $emp->company_id, 'employee_id' => $emp->id, 'captured_at' => now(), 'trigger_reason' => 'INTERVAL',
        ]);
        $admin = $this->login('admin@ametecs.io');
        $tl = $this->withToken($admin)->getJson("/api/reports/employee/{$emp->id}/timeline")->assertOk()->json('timeline');
        $row = collect($tl)->firstWhere('type', 'SCREENSHOT');
        $this->assertSame($shot->id, $row['ref']);
    }
}
