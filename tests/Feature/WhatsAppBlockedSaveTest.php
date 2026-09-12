<?php
namespace Tests\Feature;
use App\Models\ApplicationPolicy;
use App\Models\PolicyRule;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppBlockedSaveTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $u = \App\Models\User::query()->whereRelation('role','slug','COMPANY_ADMIN')->firstOrFail();
        $this->withToken($this->postJson('/api/auth/login',['email'=>$u->email,'password'=>'password'])->json('token'));
    }

    public function test_whatsapp_blocked_with_file_and_camera_protections_saves(): void
    {
        $app = ApplicationPolicy::withoutGlobalScopes()->first();
        // Exactly the screenshot: whatsapp BLOCKED, action WARN, file+image+camera ticked,
        // alongside a couple of other rows the screen sends together.
        $res = $this->putJson("/api/policies/application/{$app->id}/rules", ['rules' => [
            ['item'=>'whatsapp','label'=>'whatsapp','status'=>'BLOCKED','action'=>'WARN','confirmed'=>false,
             'protections'=>['file'=>false,'image'=>false,'camera'=>true]],
            ['item'=>'valorant','label'=>'valorant','status'=>'BLOCKED','action'=>'WARN','confirmed'=>false,
             'protections'=>['file'=>false,'image'=>false,'camera'=>false]],
            ['item'=>'excel.exe','label'=>'excel.exe','status'=>'ALLOWED','action'=>'LOG','confirmed'=>false,
             'protections'=>['file'=>false,'image'=>false,'camera'=>false]],
        ]]);
        if ($res->status() !== 200) { fwrite(STDERR, "\nSTATUS ".$res->status()." BODY ".$res->getContent()."\n"); }
        $res->assertOk();
        $w = PolicyRule::withoutGlobalScopes()->where('item','whatsapp')->first();
        $this->assertSame('BLOCKED',$w->status);
        $this->assertSame(['camera'],$w->protectionList());
    }
}
