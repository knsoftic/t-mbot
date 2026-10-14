<?php

namespace Tests\Feature;

use App\Enums\ImportRowStatus;
use App\Enums\PostTrigger;
use App\Livewire\MemberImports\Index as ImportPage;
use App\Livewire\MemberImports\Show as ReportPage;
use App\Models\BotSubscriber;
use App\Models\JoinRequest;
use App\Models\MemberImport;
use App\Models\PostingLog;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use App\Models\User;
use App\Services\MemberImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeTelegram;
use Tests\TestCase;
use ZipArchive;

class MemberImportTest extends TestCase
{
    use RefreshDatabase;

    private FakeTelegram $telegram;

    protected function setUp(): void
    {
        parent::setUp();
        $this->telegram = FakeTelegram::install();
    }

    private function group(?User $owner = null): TelegramDestination
    {
        $bot = TelegramBot::factory()->create(['user_id' => $owner?->id]);
        $bot->forceFill(['telegram_id' => 7000001, 'webhook_secret' => 'secret-1', 'updates_connected_at' => now()])->save();

        $group = TelegramDestination::factory()->group()->create(['bot_id' => $bot->id, 'name' => 'Buyers']);
        $group->forceFill(['join_request_link' => 'https://t.me/+JoinBuyers'])->save();

        return $group;
    }

    private function import(TelegramDestination $group, string $csv, array $options = [], ?User $user = null): MemberImport
    {
        return app(MemberImportService::class)->create($group, UploadedFile::fake()->createWithContent('list.csv', $csv), $options, $user);
    }

    /**
     * @return array<int, ImportRowStatus>
     */
    private function statuses(MemberImport $import): array
    {
        return $import->rows()->orderBy('row_number')->get()->mapWithKeys(fn ($row) => [$row->row_number => $row->status])->all();
    }

    private function calls(string $method): array
    {
        return array_values(array_filter($this->telegram->calls, fn ($call) => $call['method'] === $method));
    }

    #[Test]
    public function lists_are_parsed_with_reasons_for_unusable_entries(): void
    {
        $csv = "username,user_id\n@Alice_One,\n,123456789\nt.me/bob_two,\n+92 300 1234567,\n,-1001234567\nx,\n@alice_one,\n";
        $rows = collect(app(MemberImportService::class)->parse($this->file($csv), 'csv'))->keyBy('row_number');

        $this->assertSame('alice_one', $rows[2]['username']);
        $this->assertSame(123456789, $rows[3]['telegram_user_id']);
        $this->assertSame('bob_two', $rows[4]['username']);
        $this->assertSame('skipped', $rows[5]['status']);
        $this->assertStringContainsString('Phone numbers', $rows[5]['reason']);
        $this->assertStringContainsString('group / channel ID', $rows[6]['reason']);
        $this->assertStringContainsString('Not a valid', $rows[7]['reason']);
        $this->assertSame('Duplicate of row 2.', $rows[8]['reason']);

        // Without a header, a plain name is not mistaken for a username.
        $plain = app(MemberImportService::class)->parse($this->file("John Smith\njohn_smith\n710000123 @Jane_D\n"), 'txt');
        $this->assertSame([null, 'john_smith', 'jane_d'], array_column($plain, 'username'));
        $this->assertSame('skipped', $plain[0]['status']);
        $this->assertSame(710000123, $plain[2]['telegram_user_id']);

        // A bare word in a "username" column is a username.
        $columns = app(MemberImportService::class)->parse($this->file("name,username\nAli Khan,ali_khan\n"), 'csv');
        $this->assertSame('ali_khan', $columns[0]['username']);
    }

    #[Test]
    public function excel_files_are_read(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns:r="r"><sheets><sheet name="List" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<sst><si><t>Username</t></si><si><t>@excel_user</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c></row>'
            .'<row r="2"><c r="A2" t="s"><v>1</v></c></row>'
            .'<row r="3"><c r="A3"><v>5.55123456E+9</v></c></row>'
            .'<row r="4"><c r="A4" t="inlineStr"><is><t>t.me/inline_user</t></is></c></row>'
            .'</sheetData></worksheet>');
        $zip->close();

        $rows = app(MemberImportService::class)->parse($path, 'xlsx');

        $this->assertSame(['excel_user', null, 'inline_user'], array_column($rows, 'username'));
        $this->assertSame(5551234560, $rows[1]['telegram_user_id']);
    }

    #[Test]
    public function each_person_is_handled_the_way_telegram_allows(): void
    {
        $group = $this->group();
        BotSubscriber::query()->create(['bot_id' => $group->bot_id, 'telegram_user_id' => 710000333, 'first_name' => 'Sana', 'username' => 'sana']);
        JoinRequest::query()->create(['destination_id' => $group->id, 'bot_id' => $group->bot_id, 'telegram_user_id' => 710000444, 'status' => 'pending', 'requested_at' => now()]);

        foreach (['member', 'kicked', 'left', 'left', 'left'] as $status) {
            $this->telegram->respondNext('getChatMember', ['status' => $status, 'user' => ['id' => 1]]);
        }

        $import = $this->import($group, "user_id,username\n710000111,\n710000222,\n710000333,\n710000444,\n,nobody_here\n710000555,\n");
        $import->refresh();

        $this->assertSame(MemberImport::COMPLETED, $import->status);
        $this->assertEquals([
            2 => ImportRowStatus::AlreadyMember,
            3 => ImportRowStatus::Skipped,
            4 => ImportRowStatus::Invited,
            5 => ImportRowStatus::Added,
            6 => ImportRowStatus::Waiting,
            7 => ImportRowStatus::Waiting,
        ], $this->statuses($import));

        $invite = $this->calls('sendMessage')[0];
        $this->assertSame(710000333, $invite['params']['chat_id']);
        $this->assertStringContainsString('Hi Sana!', $invite['params']['text']);
        $this->assertStringContainsString('https://t.me/+JoinBuyers', $invite['params']['text']);
        $this->assertSame(PostTrigger::Invite, PostingLog::query()->sole()->trigger);

        $this->assertSame(710000444, $this->calls('approveChatJoinRequest')[0]['params']['user_id']);
        $this->assertSame('approved', JoinRequest::query()->sole()->status);
        $this->assertCount(1, $this->calls('approveChatJoinRequest'));
        $this->assertStringContainsString('Banned', $import->rows()->where('row_number', 3)->value('reason'));
    }

    #[Test]
    public function only_listed_people_are_approved_when_they_ask_to_join(): void
    {
        $group = $this->group();
        $this->telegram->respondNext('getChatMember', ['status' => 'left', 'user' => ['id' => 1]]);
        $import = $this->import($group, "710000555\n@listed_user\n");
        $this->assertSame([2 => ImportRowStatus::Waiting, 1 => ImportRowStatus::Waiting], array_reverse($this->statuses($import), true));

        $request = fn (int $id, string $username) => ['update_id' => random_int(1, 1_000_000), 'chat_join_request' => [
            'chat' => ['id' => (int) $group->chat_id, 'type' => 'supergroup'],
            'from' => ['id' => $id, 'is_bot' => false, 'first_name' => 'X', 'username' => $username],
            'user_chat_id' => $id, 'date' => time(),
        ]];

        // Someone not on the list: recorded, not approved.
        $this->postJson("/api/telegram/webhook/{$group->bot_id}", $request(710000999, 'stranger'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret-1'])->assertOk();
        $this->assertCount(0, $this->calls('approveChatJoinRequest'));
        $this->assertSame('pending', JoinRequest::query()->where('telegram_user_id', 710000999)->value('status'));

        // Listed by username and by ID: approved and marked added.
        $this->postJson("/api/telegram/webhook/{$group->bot_id}", $request(710000777, 'Listed_User'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret-1']);
        $this->postJson("/api/telegram/webhook/{$group->bot_id}", $request(710000555, 'someone'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret-1']);

        $this->assertSame([710000777, 710000555], array_column(array_column($this->calls('approveChatJoinRequest'), 'params'), 'user_id'));
        $this->assertSame([1 => ImportRowStatus::Added, 2 => ImportRowStatus::Added], $this->statuses($import));
        $this->assertSame(710000777, $import->rows()->where('username', 'listed_user')->value('telegram_user_id'));
    }

    #[Test]
    public function losing_access_to_the_chat_stops_the_import_and_check_again_continues(): void
    {
        $group = $this->group();
        $this->telegram->failNext('getChatMember', 400, 'Bad Request: chat not found');

        $import = $this->import($group, "710000111\n710000222\n");

        $import->refresh();
        $this->assertSame(MemberImport::FAILED, $import->status);
        $this->assertStringContainsString('Chat not found', $import->error);
        $this->assertSame(2, $import->counts()['pending']);

        $this->telegram->respondNext('getChatMember', ['status' => 'member', 'user' => ['id' => 710000111]]);
        $this->telegram->respondNext('getChatMember', ['status' => 'member', 'user' => ['id' => 710000222]]);
        $this->assertSame(2, app(MemberImportService::class)->recheck($import));

        $this->assertSame(MemberImport::COMPLETED, $import->fresh()->status);
        $this->assertSame(2, $import->counts()['already_member']);
    }

    #[Test]
    public function the_pages_upload_a_list_and_show_the_report(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $group = $this->group();
        $this->telegram->respondNext('getChatMember', ['status' => 'member', 'user' => ['id' => 710000111]]);

        Livewire::test(ImportPage::class)
            ->set('destination_id', (string) $group->id)
            ->set('file', UploadedFile::fake()->createWithContent('people.csv', "710000111\n+923001234567\n"))
            ->call('start')
            ->assertHasNoErrors()
            ->assertRedirect(route('member-imports.show', MemberImport::query()->sole()));

        $import = MemberImport::query()->sole();

        Livewire::test(ReportPage::class, ['import' => $import])
            ->assertSee('Already member')
            ->assertSee('Phone numbers cannot be used')
            ->assertSee('https://t.me/+JoinBuyers')
            ->set('status', 'skipped')
            ->assertDontSee('Already in the chat.');

        $csv = $this->get(route('member-imports.export', $import))->assertOk()->streamedContent();
        $this->assertStringContainsString('row,input,user_id,username,status,reason', $csv);
        $this->assertStringContainsString("'+923001234567", $csv);
    }

    #[Test]
    public function customers_only_see_their_own_imports(): void
    {
        $alice = User::factory()->customer()->create();
        $bob = User::factory()->customer()->create();
        $bobImport = $this->import($this->group($bob), "@someone\n", [], $bob);

        $this->actingAs($alice);
        $this->get(route('member-imports.show', $bobImport))->assertNotFound();
        $this->get(route('member-imports.export', $bobImport))->assertNotFound();
        $this->get('/member-imports')->assertOk()->assertDontSee('Buyers');
    }

    private function file(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $content);

        return $path;
    }
}
