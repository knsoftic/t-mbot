{{-- Code block with a copy button. Escape < and > in the slot as &lt; &gt;. --}}
@props(['title' => null])
<div class="overflow-hidden rounded-lg bg-slate-900 ring-1 ring-slate-800" x-data="{ copied: false }">
    <div class="flex items-center justify-between border-b border-white/10 px-3 py-1.5">
        <span class="font-mono text-[11px] text-slate-400">{{ $title ?? 'bash' }}</span>
        <button type="button" class="rounded px-2 py-0.5 text-[11px] text-slate-300 hover:bg-white/10"
                x-on:click="navigator.clipboard.writeText($refs.code.innerText.trim()); copied = true; setTimeout(() => copied = false, 1500)"
                x-text="copied ? 'Copied ✓' : 'Copy'">Copy</button>
    </div>
    <pre class="overflow-x-auto p-4 font-mono text-xs leading-relaxed text-slate-100"><code x-ref="code">{{ $slot }}</code></pre>
</div>
