<?php
namespace Tests\Feature;

use App\Models\ApplicationPolicy;
use App\Models\PolicyRule;
use App\Models\WebsitePolicy;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankingProfileSaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $u = \App\Models\User::query()->whereRelation('role','slug','COMPANY_ADMIN')->firstOrFail();
        $this->withToken($this->postJson('/api/auth/login',['email'=>$u->email,'password'=>'password'])->json('token'));
    }

    public function test_banking_profile_rules_save_and_read_back(): void
    {
        $app = ApplicationPolicy::withoutGlobalScopes()->first();

        // Exactly what the console's saveRuleActions sends for the Banking profile.
        $rules = [
            ['item'=>'whatsapp','label'=>'whatsapp','status'=>'BLOCKED','action'=>'CLOSE','confirmed'=>false,'protections'=>['file'=>false,'image'=>false,'camera'=>false]],
            ['item'=>'anydesk','label'=>'anydesk','status'=>'BLOCKED','action'=>'CLOSE','confirmed'=>true,'protections'=>['file'=>false,'image'=>false,'camera'=>false]],
            ['item'=>'teamviewer','label'=>'teamviewer','status'=>'BLOCKED','action'=>'CLOSE','confirmed'=>true,'protections'=>['file'=>false,'image'=>false,'camera'=>false]],
            ['item'=>'ultraviewer','label'=>'ultraviewer','status'=>'BLOCKED','action'=>'CLOSE','confirmed'=>false,'protections'=>['file'=>false,'image'=>false,'camera'=>false]],
            ['item'=>'outlook.exe','label'=>'outlook.exe','status'=>'ALLOWED','action'=>'LOG','confirmed'=>false,'protections'=>['file'=>false,'image'=>false,'camera'=>false]],
            ['item'=>'anydesk2','label'=>'anydesk2','status'=>'ALLOWED','action'=>'LOG','confirmed'=>false,'protections'=>['file'=>false,'image'=>false,'camera'=>true]],
        ];

        $res = $this->putJson("/api/policies/application/{$app->id}/rules", ['rules'=>$rules]);
        $res->assertOk();

        // Read back the way GET /policies/application does.
        $back = $this->getJson('/api/policies/application')->assertOk()->json('data.0.rules');
        $byItem = collect($back)->keyBy('item');

        $this->assertSame('CLOSE', $byItem['whatsapp']['action'] ?? null, 'whatsapp full block did not persist');
        $this->assertTrue((bool)($byItem['anydesk']['confirmed'] ?? false), 'anydesk confirmation lost');
        $this->assertSame(['camera'=>true], $byItem['anydesk2']['protections'] ?? null, 'camera protection lost');
    }

    public function test_file_block_on_a_running_desktop_app_is_refused_not_pretended(): void
    {
        $app = ApplicationPolicy::withoutGlobalScopes()->first();
        $this->putJson("/api/policies/application/{$app->id}/rules", ['rules'=>[
            ['item'=>'outlook.exe','label'=>'outlook.exe','status'=>'ALLOWED','action'=>'LOG','confirmed'=>false,'protections'=>['file'=>true,'image'=>false,'camera'=>false]],
        ]])->assertOk()->assertJsonPath('unenforceable.0', 'outlook → file');
        $this->assertNull(PolicyRule::withoutGlobalScopes()->where('item','outlook')->first()->protections);
        // AnyDesk has its own transfer switch, so it IS accepted.
        $this->putJson("/api/policies/application/{$app->id}/rules", ['rules'=>[
            ['item'=>'anydesk','label'=>'anydesk','status'=>'ALLOWED','action'=>'LOG','confirmed'=>true,'protections'=>['file'=>true,'image'=>false,'camera'=>false]],
        ]])->assertOk();
    }
}
