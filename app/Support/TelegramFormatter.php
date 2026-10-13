<?php

namespace App\Support;

use App\Enums\ParseMode;

/**
 * Renders post text for the admin preview the way Telegram would, as safe HTML.
 *
 * Everything is HTML-escaped first; only Telegram's own formatting tags are
 * restored afterwards, so stored content can never inject markup or scripts.
 */
final class TelegramFormatter
{
    private const TAGS = ['b', 'strong', 'i', 'em', 'u', 'ins', 's', 'strike', 'del', 'code', 'pre', 'blockquote', 'tg-spoiler'];

    public static function toHtml(?string $text, ?ParseMode $mode): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $html = e($text);

        $html = match ($mode) {
            ParseMode::Html => self::restoreHtmlTags($html),
            ParseMode::MarkdownV2 => self::markdown($html),
            default => self::linkify($html),
        };

        return nl2br($html, false);
    }

    private static function restoreHtmlTags(string $escaped): string
    {
        $tags = implode('|', array_map('preg_quote', self::TAGS));
        $html = preg_replace("#&lt;(/?)({$tags})&gt;#i", '<$1$2>', $escaped);
        $html = preg_replace('#&lt;span class=&quot;tg-spoiler&quot;&gt;(.*?)&lt;/span&gt;#is', '<tg-spoiler>$1</tg-spoiler>', $html);
        $html = preg_replace('#&lt;a href=&quot;(https?://[^\s]+?)&quot;&gt;#i', '<a href="$1" target="_blank" rel="noopener noreferrer nofollow">', $html);
        $html = str_ireplace('&lt;/a&gt;', '</a>', $html);

        return str_replace(['<tg-spoiler>', '</tg-spoiler>'], ['<span class="rounded bg-slate-400/40 px-0.5 blur-[3px] hover:blur-none">', '</span>'], $html);
    }

    private static function markdown(string $escaped): string
    {
        $rules = [
            '/```(.+?)```/s' => '<pre>$1</pre>',
            '/`([^`]+)`/' => '<code>$1</code>',
            '/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/' => '<a href="$2" target="_blank" rel="noopener noreferrer nofollow">$1</a>',
            '/(?<!\\\\)\*(?!\s)(.+?)(?<!\s|\\\\)\*/s' => '<b>$1</b>',
            '/(?<!\\\\)__(.+?)__/s' => '<u>$1</u>',
            '/(?<![\\\\\w])_(?!\s)(.+?)(?<!\s|\\\\)_(?!\w)/s' => '<i>$1</i>',
            '/(?<!\\\\)~(.+?)~/s' => '<s>$1</s>',
            '/\|\|(.+?)\|\|/s' => '<span class="rounded bg-slate-400/40 px-0.5 blur-[3px] hover:blur-none">$1</span>',
        ];

        $html = preg_replace(array_keys($rules), array_values($rules), $escaped);

        // MarkdownV2 escapes special characters with a backslash.
        return preg_replace('/\\\\([_*\[\]()~`>#+\-=|{}.!\\\\])/', '$1', $html);
    }

    private static function linkify(string $escaped): string
    {
        return preg_replace(
            '#\bhttps?://[^\s<]+#i',
            '<a href="$0" target="_blank" rel="noopener noreferrer nofollow">$0</a>',
            $escaped,
        );
    }
}
