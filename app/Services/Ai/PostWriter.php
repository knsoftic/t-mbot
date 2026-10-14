<?php

namespace App\Services\Ai;

use App\Contracts\TextGenerator;
use App\Services\SettingsService;
use Illuminate\Support\Str;

/**
 * Turns a short brief into a Telegram post draft using the local model.
 *
 * The draft is only ever placed in the content form for a person to review;
 * it is never posted automatically.
 */
class PostWriter
{
    public const LANGUAGES = [
        'english' => 'English',
        'urdu' => 'Urdu (Urdu script)',
        'roman_urdu' => 'Roman Urdu (Urdu written in Latin letters, e.g. "Aaj ka din behtareen hai")',
    ];

    public const TONES = ['friendly', 'professional', 'exciting', 'informative'];

    public const LENGTHS = [
        'short' => '1 to 2 sentences, under 40 words',
        'medium' => '3 to 5 sentences, under 90 words',
        'long' => 'two short paragraphs, under 180 words',
    ];

    private const SYSTEM = <<<'TXT'
You are a social media copywriter for a Telegram channel. Write exactly one post.
Output only the post text: no title, no explanation, no notes, no quotation marks around it.
Never invent facts, prices, dates, statistics, links, results or guarantees that are not in the brief. If details are missing, keep the post general.
TXT;

    public function __construct(
        private readonly TextGenerator $generator,
        private readonly SettingsService $settings,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('ai.enabled');
    }

    private function generatorModel(): string
    {
        return (string) $this->settings->get('ai.model');
    }

    /**
     * @param  array{topic: string, language?: string, tone?: string, length?: string, emojis?: bool, hashtags?: bool, for?: string}  $brief
     */
    public function draft(array $brief): string
    {
        if (! $this->enabled()) {
            throw AiException::disabled();
        }

        $isCaption = ($brief['for'] ?? 'text') === 'caption';
        $length = $brief['length'] ?? 'medium';

        $text = $this->generator->generate(self::SYSTEM, $this->prompt($brief), [
            'temperature' => 0.7,
            'max_tokens' => match ($length) {
                'short' => 120,
                'long' => 450,
                default => 250,
            },
        ]);

        return $this->clean($text, $isCaption ? 1024 : 4096);
    }

    /**
     * @param  array<string, mixed>  $brief
     */
    public function prompt(array $brief): string
    {
        $language = self::LANGUAGES[$brief['language'] ?? 'english'] ?? self::LANGUAGES['english'];
        $tone = in_array($brief['tone'] ?? null, self::TONES, true) ? $brief['tone'] : 'friendly';
        $length = self::LENGTHS[$brief['length'] ?? 'medium'] ?? self::LENGTHS['medium'];
        $isCaption = ($brief['for'] ?? 'text') === 'caption';

        return implode("\n", [
            'Topic / idea: '.trim($brief['topic']),
            'Language: '.$language,
            'Tone: '.$tone,
            'Length: '.$length,
            'Emojis: '.(($brief['emojis'] ?? true) ? 'use a few relevant emojis' : 'no emojis'),
            'Hashtags: '.(($brief['hashtags'] ?? false) ? 'end with 3 to 5 relevant hashtags' : 'no hashtags'),
            'Format: '.($isCaption ? 'a caption shown under an image or video, under 900 characters' : 'a Telegram text message'),
        ]);
    }

    /**
     * Small models like to add a preamble or wrap the answer in quotes; strip that.
     */
    public function clean(string $text, int $limit): string
    {
        $text = trim(str_replace("\r\n", "\n", $text));
        $text = trim(preg_replace('/<think>.*?(<\/think>|$)/su', '', $text));
        $text = preg_replace('/^```[a-z]*\n?|\n?```$/i', '', $text);

        $lines = explode("\n", trim($text));

        if (count($lines) > 1 && preg_match('/^(sure|certainly|of course|here(\'|’)?s|here is|below is)\b.*:?\s*$/iu', trim($lines[0]))) {
            array_shift($lines);
        }

        $text = trim(implode("\n", $lines));

        if (preg_match('/^(["“”\'])(.*)(["“”\'])$/su', $text, $m)) {
            $text = trim($m[2]);
        }

        if ($text === '') {
            throw AiException::emptyAnswer();
        }

        if ($this->looksLikeReasoning($text)) {
            throw AiException::reasoningLeak($this->generatorModel());
        }

        return Str::limit($text, $limit - 1, '…');
    }

    /**
     * Reasoning models sometimes write out their thoughts ("Hmm, the user wants…",
     * "We are writing a Telegram post… Option 1:") instead of the post itself.
     * One strong sign is enough; weak signs (that a real post could contain) need two.
     */
    public function looksLikeReasoning(string $text): bool
    {
        $strong = '/\b(the user (wants|asked|is asking|needs|said)|user wants me|we are (writing|asked to write|to write)|(write|writing|draft|drafting) (a|the|one) (short |single )?telegram (post|message)|the instructions? (say|says|is to))\b/iu';

        if (preg_match($strong, mb_substr($text, 0, 600))) {
            return true;
        }

        $weak = [
            '/\blet\'?s (draft|check|think|count|brainstorm)\b/iu',
            '/^\s*(option|draft|version) \d+\s*:/imu',
            '/^\s*(steps|final answer|word count|constraints|requirements)\s*:/imu',
            '/^\s*(hmm|okay so|alright so|first,? i)\b/iu',
            '/\b(word|sentence) count\b|\bwords?:\s*\d+/iu',
        ];

        return collect($weak)->filter(fn (string $pattern) => preg_match($pattern, $text))->count() >= 2;
    }
}
