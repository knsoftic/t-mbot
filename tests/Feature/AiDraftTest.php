<?php

namespace Tests\Feature;

use App\Contracts\TextGenerator;
use App\Jobs\GenerateAiDraftJob;
use App\Livewire\Contents\Form;
use App\Livewire\Settings\Index as Settings;
use App\Models\User;
use App\Services\Ai\AiException;
use App\Services\Ai\OllamaTextGenerator;
use App\Services\Ai\PostWriter;
use App\Services\HealthService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiDraftTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = 'http://127.0.0.1:11434/api/chat';

    private const TAGS = 'http://127.0.0.1:11434/api/tags';

    private function enableAi(): void
    {
        app(SettingsService::class)->set(['ai.enabled' => true, 'ai.model' => 'qwen2.5:1.5b']);
    }

    private function fakeAnswer(string $content): void
    {
        Http::fake([self::CHAT => Http::response(['message' => ['role' => 'assistant', 'content' => $content], 'done' => true])]);
    }

    #[Test]
    public function the_generator_calls_the_local_ollama_chat_api(): void
    {
        $this->enableAi();
        $this->fakeAnswer("  Happy Monday! ☀️  \n");

        $text = app(TextGenerator::class)->generate('system prompt', 'user prompt', ['max_tokens' => 120]);

        $this->assertSame('Happy Monday! ☀️', $text);
        Http::assertSent(fn (Request $request) => $request->url() === self::CHAT
            && $request['model'] === 'qwen2.5:1.5b'
            && $request['stream'] === false
            && $request['think'] === false
            && $request['messages'][0] === ['role' => 'system', 'content' => 'system prompt']
            && $request['messages'][1] === ['role' => 'user', 'content' => 'user prompt']
            && $request['options']['num_predict'] === 120);
    }

    #[Test]
    public function a_missing_model_explains_how_to_install_it(): void
    {
        $this->enableAi();
        Http::fake([self::CHAT => Http::response(['error' => 'model "qwen2.5:1.5b" not found, try pulling it first'], 404)]);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('ollama pull qwen2.5:1.5b');

        app(TextGenerator::class)->generate('s', 'p');
    }

    #[Test]
    public function servers_that_refuse_the_think_flag_are_asked_again_without_it(): void
    {
        $this->enableAi();
        Http::fakeSequence(self::CHAT)
            ->push(['error' => 'unknown field "think"'], 400)
            ->push(['message' => ['content' => 'Hello!']]);

        $this->assertSame('Hello!', app(TextGenerator::class)->generate('s', 'p'));
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => ! isset($request['think']));
    }

    #[Test]
    public function a_thinking_only_model_is_reported_instead_of_returning_nothing(): void
    {
        $this->enableAi();
        Http::fake([self::CHAT => Http::response(['message' => ['content' => '', 'thinking' => 'We are writing a post…'], 'done_reason' => 'length'])]);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('thinking-only');

        app(TextGenerator::class)->generate('s', 'p');
    }

    #[Test]
    public function an_unreachable_server_gives_a_clear_error(): void
    {
        Http::fake([self::TAGS => Http::failedConnection('Failed to connect to 127.0.0.1 port 11434: Connection refused')]);

        $this->expectException(AiException::class);
        $this->expectExceptionMessage('not reachable at http://127.0.0.1:11434');

        app(TextGenerator::class)->models();
    }

    #[Test]
    public function the_installed_models_are_listed(): void
    {
        Http::fake([self::TAGS => Http::response(['models' => [['name' => 'qwen2.5:1.5b'], ['name' => 'llama3.2:1b']]])]);

        $this->assertSame(['qwen2.5:1.5b', 'llama3.2:1b'], app(TextGenerator::class)->models());
    }

    #[Test]
    public function the_writer_strips_preambles_quotes_and_respects_the_caption_limit(): void
    {
        $writer = app(PostWriter::class);

        $this->assertSame('Big sale today! 🎉', $writer->clean("Sure! Here's your post:\n\"Big sale today! 🎉\"", 4096));
        $this->assertSame('Line one', $writer->clean("```\nLine one\n```", 4096));
        $this->assertSame('Good morning!', $writer->clean("<think>\nThe user wants a greeting.\n</think>\n\nGood morning!", 4096));
        $this->assertSame(1024, mb_strlen($writer->clean(str_repeat('a', 3000), 1024)));
    }

    #[Test]
    public function markdown_and_unwanted_hashtags_are_removed(): void
    {
        $writer = app(PostWriter::class);
        $raw = "## Weekend Tip\n\nStart your day with **a short walk** 🚶 and __more water__ 💧!\n\n#HealthySunday #WalkAndDrink";

        $this->assertSame("Weekend Tip\n\nStart your day with a short walk 🚶 and more water 💧!", $writer->clean($raw, 4096, hashtags: false));
        $this->assertStringEndsWith('#HealthySunday #WalkAndDrink', $writer->clean($raw, 4096, hashtags: true));
        $this->assertSame('Stay hydrated 💧', $writer->clean('Stay hydrated 💧 #health #water', 4096, hashtags: false));
    }

    #[Test]
    public function a_cut_off_answer_ends_at_the_last_finished_sentence(): void
    {
        $this->enableAi();
        Http::fake([self::CHAT => Http::response(['message' => ['content' => 'Aaj ka din behtareen hai! Thori walk karein. Pani zyada piy'], 'done_reason' => 'length'])]);

        $this->assertSame('Aaj ka din behtareen hai! Thori walk karein.', app(TextGenerator::class)->generate('s', 'p'));
        $this->assertSame('Great day ☀️', OllamaTextGenerator::dropUnfinishedSentence('Great day ☀️ and then we'));
    }

    #[Test]
    public function roman_urdu_drafts_get_a_style_example_and_a_repeat_penalty(): void
    {
        $this->enableAi();
        $this->fakeAnswer('Assalam o Alaikum dosto! Weekend par walk zaroor karein. 🌿');

        app(PostWriter::class)->draft(['topic' => 'Weekend walk', 'language' => 'roman_urdu']);

        Http::assertSent(fn (Request $request) => str_contains($request['messages'][1]['content'], 'Roman Urdu style example')
            && $request['options']['repeat_penalty'] > 1.1);
    }

    #[Test]
    public function written_out_reasoning_is_rejected_instead_of_becoming_a_draft(): void
    {
        $this->expectException(AiException::class);
        $this->expectExceptionMessage('wrote its reasoning');

        app(PostWriter::class)->clean("Hmm, the user wants me to write a Telegram post as a social media copywriter.\nI need to follow their rules.", 4096);
    }

    #[Test]
    public function reasoning_detection_does_not_flag_normal_posts(): void
    {
        $writer = app(PostWriter::class);

        $this->assertTrue($writer->looksLikeReasoning("We are writing a Telegram post for a channel.\nThe topic is a weekend tip."));
        $this->assertTrue($writer->looksLikeReasoning("Steps:\n1. Greet\nLet's draft:\nOption 1: Hey weekend vibes!"));

        $this->assertFalse($writer->looksLikeReasoning("Let's check out our weekend sale! 🛍️ Up to 30% off at the store."));
        $this->assertFalse($writer->looksLikeReasoning('Hey weekend vibes! 🚶 Try a short walk outside and sip more water. Your body will thank you! 💧'));
        $this->assertFalse($writer->looksLikeReasoning('Aaj ka din behtareen hai! Thori walk karein aur pani zyada piyein. 💧'));
    }

    #[Test]
    public function the_prompt_carries_the_brief(): void
    {
        $prompt = app(PostWriter::class)->prompt([
            'topic' => 'New Urdu course starts 1 November',
            'language' => 'roman_urdu',
            'tone' => 'exciting',
            'length' => 'short',
            'emojis' => false,
            'hashtags' => true,
            'for' => 'caption',
        ]);

        $this->assertStringContainsString('New Urdu course starts 1 November', $prompt);
        $this->assertStringContainsString('Roman Urdu', $prompt);
        $this->assertStringContainsString('Tone: exciting', $prompt);
        $this->assertStringContainsString('no emojis', $prompt);
        $this->assertStringContainsString('hashtags', $prompt);
        $this->assertStringContainsString('caption', $prompt);
    }

    #[Test]
    public function the_writer_refuses_while_ai_is_turned_off(): void
    {
        Http::fake();

        $this->expectException(AiException::class);

        try {
            app(PostWriter::class)->draft(['topic' => 'Anything']);
        } finally {
            Http::assertNothingSent();
        }
    }

    #[Test]
    public function the_content_form_fills_the_text_with_a_draft_without_saving_it(): void
    {
        $this->enableAi();
        $this->fakeAnswer('Monday is a fresh start. Make it count! 💪');
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Form::class)
            ->call('openAi')
            ->assertSet('showAi', true)
            ->set('aiTopic', 'Monday motivation for our readers')
            ->call('generateAi')
            ->assertHasNoErrors()
            ->assertSet('aiStatus', 'done')
            ->assertSet('text', 'Monday is a fresh start. Make it count! 💪')
            ->assertSet('title', 'Monday motivation for our readers')
            ->assertSet('showAi', false);

        $this->assertDatabaseCount('contents', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ai.draft_requested']);
    }

    #[Test]
    public function media_content_gets_the_draft_as_its_caption(): void
    {
        $this->enableAi();
        $this->fakeAnswer('Our new office, finally open!');
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Form::class)
            ->set('type', 'image')
            ->set('title', 'Office photo')
            ->set('aiTopic', 'Photo of our new office')
            ->call('generateAi')
            ->assertSet('caption', 'Our new office, finally open!')
            ->assertSet('text', '')
            ->assertSet('title', 'Office photo');

        Http::assertSent(fn (Request $request) => str_contains($request['messages'][1]['content'], 'caption'));
    }

    #[Test]
    public function the_form_shows_ai_errors_instead_of_crashing(): void
    {
        $this->enableAi();
        Http::fake([self::CHAT => Http::response(['error' => 'model "qwen2.5:1.5b" not found'], 404)]);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Form::class)
            ->set('aiTopic', 'Weekend offer')
            ->call('generateAi')
            ->assertSet('aiStatus', 'failed')
            ->assertSet('aiError', fn ($error) => str_contains($error, 'ollama pull qwen2.5:1.5b'))
            ->assertSet('text', '');
    }

    #[Test]
    public function nothing_is_queued_while_ai_is_off_or_the_topic_is_missing(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Form::class)
            ->set('aiTopic', 'Weekend offer')
            ->call('generateAi')
            ->assertSet('aiStatus', 'failed')
            ->assertSet('aiError', fn ($error) => str_contains($error, 'turned off'));

        $this->enableAi();

        Livewire::test(Form::class)
            ->set('aiTopic', '')
            ->call('generateAi')
            ->assertHasErrors(['aiTopic' => 'required']);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_ai_button_only_appears_when_ai_is_enabled(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/contents/create')->assertOk()->assertDontSee('AI se likho');

        $this->enableAi();

        $this->get('/contents/create')->assertOk()->assertSee('AI se likho');
    }

    #[Test]
    public function only_one_draft_is_written_at_a_time(): void
    {
        $this->enableAi();
        Http::fake();
        $lock = Cache::lock('ai:generation', 60);
        $lock->get();

        $job = (new GenerateAiDraftJob('ai:draft:1:test', ['topic' => 'Anything']))->withFakeQueueInteractions();
        $job->handle(app(PostWriter::class));

        $job->assertReleased(delay: 5);
        Http::assertNothingSent();
        $lock->release();
    }

    #[Test]
    public function the_settings_page_checks_the_ai_server(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Http::fake([self::TAGS => Http::response(['models' => [['name' => 'qwen2.5:1.5b']]])]);

        Livewire::test(Settings::class)
            ->set('tab', 'ai')
            ->call('checkAi')
            ->assertSet('aiCheck.ok', true)
            ->set('form.ai.model', 'llama3.2:3b')
            ->call('checkAi')
            ->assertSet('aiCheck.ok', false)
            ->assertSet('aiCheck.message', fn ($message) => str_contains($message, 'ollama pull llama3.2:3b'));
    }

    #[Test]
    public function ai_settings_are_validated_and_saved(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(Settings::class)
            ->set('form.ai.model', 'bad model; rm -rf /')
            ->call('save')
            ->assertHasErrors(['form.ai.model'])
            ->set('form.ai.model', 'qwen2.5:1.5b')
            ->set('form.ai.enabled', true)
            ->set('form.ai.default_language', 'roman_urdu')
            ->call('save')
            ->assertHasNoErrors();

        $settings = app(SettingsService::class);
        $this->assertTrue($settings->get('ai.enabled'));
        $this->assertSame('roman_urdu', $settings->get('ai.default_language'));
    }

    #[Test]
    public function system_health_checks_the_ai_only_when_enabled(): void
    {
        Http::fake([
            self::TAGS => Http::response(['models' => [['name' => 'qwen2.5:1.5b']]]),
            '*' => Http::response(['ok' => false], 404), // Telegram ping
        ]);

        $this->assertNotContains('ai', array_column(app(HealthService::class)->checks(), 'key'));

        $this->enableAi();
        $ai = collect(app(HealthService::class)->checks())->firstWhere('key', 'ai');

        $this->assertSame('healthy', $ai['status']);
    }
}
