<div>
    <x-page-header title="User Guide" description="Telegram Automation ko step-by-step istemal karne ka tareeqa — pehle bot se le kar campaign chalane aur monitoring tak.">
        <x-slot:actions>
            <a href="{{ route('help.api') }}" wire:navigate class="btn btn-secondary"><x-icon name="document" class="size-4" /> API Docs</a>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
        {{-- Table of contents --}}
        <aside class="lg:col-span-1">
            <nav class="card p-3 lg:sticky lg:top-24 lg:max-h-[calc(100vh-8rem)] lg:overflow-y-auto">
                <div class="px-2 pb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Is guide mein</div>
                @foreach ($sections as $id => $label)
                    <a href="#{{ $id }}" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white">
                        <span class="w-5 text-right text-xs tabular-nums text-slate-400">{{ $loop->iteration }}.</span> {{ $label }}
                    </a>
                @endforeach
            </nav>
        </aside>

        <div class="space-y-6 lg:col-span-3">

            {{-- 1. Quick start --}}
            <x-docs.section id="quick-start" title="1. Quick start: pehli post 6 qadam mein" icon="bolt">
                <p>Bilkul naye system par pehli post bhejne ke liye yeh 6 kaam is tarteeb se karein. Har qadam ki tafseel neeche apne section mein hai.</p>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ([
                        ['1', 'Bot add karein', 'Telegram mein @BotFather se token le kar Bots page par daalein.', 'bots.index', 'bot'],
                        ['2', 'Channel jorein', 'Bot ko channel ka admin banayein, phir Channels page par add karke Test message.', 'destinations.channels', 'megaphone'],
                        ['3', 'Content banayein', 'Content Library mein text, image ya video post banayein.', 'contents.create', 'photo'],
                        ['4', 'Campaign banayein', 'Campaign wizard mein bot, channel, content aur schedule chunein.', 'campaigns.create', 'bolt'],
                        ['5', 'Activate karein', 'Wizard ke aakhri step par "Save & activate". Run now se foran ek post bhejein.', 'campaigns.index', 'play'],
                        ['6', 'Nateeja dekhein', 'Posting Logs mein har post ka status, message ID aur error milta hai.', 'logs.index', 'list'],
                    ] as [$n, $title, $text, $route, $icon])
                        <a href="{{ route($route) }}" wire:navigate class="flex gap-3 rounded-xl p-4 ring-1 ring-slate-200 transition hover:bg-slate-50 hover:ring-brand-300 dark:ring-slate-700 dark:hover:bg-slate-800/50">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand-600 text-sm font-semibold text-white">{{ $n }}</span>
                            <span>
                                <span class="block font-semibold text-slate-900 dark:text-white">{{ $title }}</span>
                                <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $text }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
                <x-docs.callout type="tip" title="Mashwara">Pehli campaign <strong>Test mode</strong> ke saath chalayein (wizard ka step 3). Is tarah posts ek test chat mein jati hain aur asal channel saaf rehta hai.</x-docs.callout>
            </x-docs.section>

            {{-- 2. Account --}}
            <x-docs.section id="account" title="2. Login aur account" icon="user">
                <h3>Login</h3>
                <p><a href="{{ route('login') }}">Login page</a> par e-mail aur password daalein. Lagataar 5 ghalat koshishon ke baad 1 minute ke liye login ruk jata hai. Disable kiye gaye account se login nahi hota.</p>
                <h3>Password bhool gaye</h3>
                <x-docs.steps>
                    <li>Login page par <strong>Forgot password?</strong> dabayein aur apna e-mail daalein.</li>
                    <li>E-mail mein aaye link se naya password set karein.</li>
                    <li>E-mail na aaye to koi Super Admin <strong>Administrators</strong> page se aap ka password set kar sakta hai.</li>
                </x-docs.steps>
                <h3>Profile, password aur timezone</h3>
                <p>Upar daayein kone mein user icon → <strong>Profile & password</strong>. Yahan naam, e-mail aur <strong>timezone</strong> badal sakte hain; panel ki saari tareekhein isi timezone mein dikhti hain. Password badalne par baqi devices se khud logout ho jata hai.</p>
                <h3>API token</h3>
                <p>Mobile app ya doosre system ke liye <strong>Profile → API tokens</strong> mein token banayein. Token sirf ek dafa dikhta hai; usay foran copy karke mehfooz rakhein. Tafseel <a href="{{ route('help.api') }}" wire:navigate>API Docs</a> mein hai.</p>
                <h3>Dark mode</h3>
                <p>Top bar mein chaand / suraj wala button light aur dark mode badalta hai.</p>
            </x-docs.section>

            {{-- 3. Dashboard --}}
            <x-docs.section id="dashboard" title="3. Dashboard" icon="home">
                <p><a href="{{ route('dashboard') }}" wire:navigate>Dashboard</a> poore system ka khulasa dikhata hai aur har 20 second mein khud refresh hota hai.</p>
                <table>
                    <thead><tr><th>Hissa</th><th>Kya batata hai</th></tr></thead>
                    <tbody>
                        <tr><td>Posts sent / failed today</td><td>Aaj (aap ke timezone ke hisaab se) kitni posts gayin aur kitni fail huin. Click karne par Posting Logs khulte hain.</td></tr>
                        <tr><td>Pending posts</td><td>Active aur paused campaigns mein abhi kitne items baqi hain.</td></tr>
                        <tr><td>Active campaigns</td><td>Is waqt chalti hui campaigns aur kitni paused hain.</td></tr>
                        <tr><td>Chhote cards</td><td>Bots, active bots, channels, groups, content, scheduled aur completed campaigns ki ginti.</td></tr>
                        <tr><td>Campaigns needing attention</td><td>Failed campaigns aur un ki wajah. Sirf tab dikhta hai jab koi campaign fail ho.</td></tr>
                        <tr><td>Recent activity</td><td>Aakhri posts (sent / failed / skipped) aur campaign events (started, paused, stopped).</td></tr>
                        <tr><td>Next scheduled posts</td><td>Agli posts kab jayengi, campaign ke hisaab se.</td></tr>
                    </tbody>
                </table>
            </x-docs.section>

            {{-- 4. Bots --}}
            <x-docs.section id="bots" title="4. Bots" icon="bot">
                <p>Har post kisi bot ke zariye jati hai. Aap jitne chahein bots rakh sakte hain; har bot alag channels aur campaigns chala sakta hai.</p>
                <h3>Telegram mein bot banana</h3>
                <x-docs.steps>
                    <li>Telegram mein <strong>@BotFather</strong> kholein aur <code>/newbot</code> bhejein.</li>
                    <li>Bot ka naam aur username dein (username <code>bot</code> par khatam hona chahiye, jaise <code>hunario_post_bot</code>).</li>
                    <li>BotFather ek token dega, jaise <code>123456789:AAH...</code>. Yeh password jaisa hai; kisi ko na dein.</li>
                </x-docs.steps>
                <h3>Panel mein bot add karna</h3>
                <x-docs.steps>
                    <li><a href="{{ route('bots.index') }}" wire:navigate>Telegram → Bots</a> → <strong>Add bot</strong>.</li>
                    <li>Naam likhein (sirf panel ke liye, jaise "Marketing Bot") aur token paste karein.</li>
                    <li><strong>Save & test connection</strong> dabayein. System Telegram se token check karta hai aur bot ka @username khud bhar deta hai.</li>
                </x-docs.steps>
                <table>
                    <thead><tr><th>Button / column</th><th>Kaam</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Test</strong></td><td>Token dobara check karta hai. "Connected" ya error ka wazeh message dikhata hai.</td></tr>
                        <tr><td>Pause / Play icon</td><td>Bot disable / enable. Disabled bot se koi post nahi jati.</td></tr>
                        <tr><td>Pencil icon</td><td>Naam, status, description ya naya token. Token khaali chhorein to purana hi rehta hai.</td></tr>
                        <tr><td>Trash icon</td><td>Bot delete. Agar koi active campaign is bot ko istemal kar rahi ho to pehle use stop karna hoga. Purani posting history mehfooz rehti hai.</td></tr>
                        <tr><td>Connection</td><td>Not tested / Connected / Failed. System har ghante khud bhi tokens check karta hai.</td></tr>
                    </tbody>
                </table>
                <x-docs.callout type="info">Token database mein encrypted rehta hai aur save hone ke baad kabhi dobara nahi dikhta; list mein sirf <code>123456789:••••</code> nazar aata hai.</x-docs.callout>
            </x-docs.section>

            {{-- 5. Destinations --}}
            <x-docs.section id="destinations" title="5. Channels aur Groups" icon="megaphone">
                <p>Destination woh chat hai jahan posts jati hain: channel, group ya supergroup. Sidebar ka <strong>Channels</strong> sirf channels dikhata hai aur <strong>Groups</strong> groups / supergroups.</p>
                <h3>Pehle Telegram mein</h3>
                <ul>
                    <li><strong>Channel:</strong> channel kholein → Administrators → Add admin → apna bot dhoondein → <strong>Post messages</strong> permission on karein.</li>
                    <li><strong>Group:</strong> bot ko group mein add karein; members ko messages bhejne ki ijazat honi chahiye (ya bot ko admin bana dein).</li>
                </ul>
                <h3>Panel mein add karna</h3>
                <x-docs.steps>
                    <li><a href="{{ route('destinations.channels') }}" wire:navigate>Channels</a> ya <a href="{{ route('destinations.groups') }}" wire:navigate>Groups</a> → <strong>Add</strong>.</li>
                    <li>Bot chunein, naam likhein aur type (Channel / Group / Supergroup) chunein.</li>
                    <li><strong>Chat ID or @username</strong>: public chat ke liye <code>@channelname</code> ya <code>t.me/channelname</code>; private chat ke liye numeric ID jaise <code>-1001234567890</code>.</li>
                    <li>Save karein, phir <strong>Test message</strong> dabayein. Kuch second mein result isi page par aa jata hai.</li>
                </x-docs.steps>
                <table>
                    <thead><tr><th>Button</th><th>Kaam</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Test message</strong></td><td>Check karta hai ke bot chat dekh sakta hai aur post kar sakta hai, phir ek test message bhejta hai. Kamyabi par numeric chat ID khud save ho jati hai.</td></tr>
                        <tr><td>Shield icon</td><td>Sirf permissions check karta hai, koi message nahi bhejta.</td></tr>
                        <tr><td>Use as a test chat</td><td>Is chat ko Test mode ke liye mark karta hai (section 11).</td></tr>
                    </tbody>
                </table>
                <x-docs.callout type="warning" title="Private chat ki ID kaise milegi?">Chat ka koi message <strong>@userinfobot</strong> ko forward karein, ya thori der ke liye <strong>@RawDataBot</strong> ko group mein add karein. Channel IDs <code>-100</code> se shuru hoti hain.</x-docs.callout>
            </x-docs.section>

            {{-- 6. Content --}}
            <x-docs.section id="content" title="6. Content Library" icon="photo">
                <p>Yahan woh saari posts rehti hain jo campaigns bhejti hain. Ek content ko kai campaigns mein istemal kar sakte hain.</p>
                <h3>Content types</h3>
                <table>
                    <thead><tr><th>Type</th><th>Telegram mein</th><th>Hadd</th></tr></thead>
                    <tbody>
                        <tr><td>Text</td><td>Sirf message</td><td>4096 characters</td></tr>
                        <tr><td>Image</td><td>Photo + caption</td><td>Upload 10 MB, URL 5 MB</td></tr>
                        <tr><td>Video</td><td>Video + caption</td><td>Upload 50 MB, URL 20 MB</td></tr>
                        <tr><td>Document</td><td>File (PDF, ZIP, DOCX…)</td><td>Upload 50 MB, URL 20 MB</td></tr>
                        <tr><td>Audio</td><td>Music file</td><td>Upload 50 MB, URL 20 MB</td></tr>
                        <tr><td>Animation / GIF</td><td>GIF ya chhoti silent video</td><td>Upload 50 MB, URL 20 MB</td></tr>
                    </tbody>
                </table>
                <p>Caption zyada se zyada 1024 characters ka ho sakta hai.</p>
                <h3>Media kahan se aaye</h3>
                <table>
                    <thead><tr><th>Source</th><th>Kab istemal karein</th></tr></thead>
                    <tbody>
                        <tr><td><strong>External URL</strong></td><td>File pehle se kisi website par hai. Link seedha file ka ho (jaise <code>https://site.com/video.mp4</code>), HTML page ka nahi, aur <code>https://</code> se shuru ho. <strong>Check URL</strong> se pehle hi pata chal jata hai ke link chalta hai ya nahi.</td></tr>
                        <tr><td><strong>Uploaded file</strong></td><td>File aap ke computer par hai. Server par mehfooz rehti hai.</td></tr>
                        <tr><td><strong>Telegram file ID</strong></td><td>File pehle hi isi bot ke zariye Telegram par ja chuki hai aur aap ke paas us ki file_id hai.</td></tr>
                    </tbody>
                </table>
                <x-docs.callout type="info">Pehli dafa post hone ke baad system Telegram ki file_id yaad rakhta hai; agli dafa wahi file dobara download ya upload nahi hoti.</x-docs.callout>
                <h3>Naya content banana</h3>
                <x-docs.steps>
                    <li><a href="{{ route('contents.create') }}" wire:navigate>Content → New content</a>.</li>
                    <li>Title likhein (sirf panel ke liye) aur type chunein.</li>
                    <li>Media wale type ke liye source chunein: URL, upload ya file ID.</li>
                    <li>Text ya caption likhein. <strong>Formatting</strong> mein HTML chunein to <code>&lt;b&gt;bold&lt;/b&gt;</code>, <code>&lt;i&gt;italic&lt;/i&gt;</code>, <code>&lt;a href="https://..."&gt;link&lt;/a&gt;</code> chal jate hain.</li>
                    <li>Tags comma se likhein (jaise <code>promo, video</code>). Naye tags khud ban jate hain.</li>
                    <li>Daayein taraf <strong>Live preview</strong> dekhein, phir <strong>Save content</strong>.</li>
                </x-docs.steps>
                <h3>List mein actions</h3>
                <ul>
                    <li><strong>Eye:</strong> Telegram jaisa preview · <strong>Pencil:</strong> edit · <strong>Copy:</strong> duplicate (upload ki gayi file ki bhi alag copy banti hai)</li>
                    <li><strong>Pause / Play:</strong> inactive / active. Inactive content campaign mein aane par <em>skip</em> ho jata hai.</li>
                    <li><strong>Trash:</strong> delete. Jo content kisi adhoori campaign mein hai, woh delete nahi hota; pehle use sequence se hatayein.</li>
                    <li>Upar search (title, text, caption, tag) aur type / status / tag filters.</li>
                </ul>
                <p><a href="{{ route('tags.index') }}" wire:navigate>Categories / Tags</a> page par tags ka naam aur rang badal sakte hain.</p>
            </x-docs.section>

            {{-- AI drafts --}}
            <x-docs.section id="ai" title="AI se post likhna (offline)" icon="sparkles">
                <p>Content form mein <strong>✨ AI se likho</strong> button aap ke idea se post ya caption ka draft likhta hai. Model isi server par chalta hai (Ollama); koi data kisi bahar ki AI company ko nahi jata aur koi API key nahi chahiye. AI sirf <strong>draft</strong> likhta hai: aap parh kar theek karein, phir Save. Khud kuch post nahi hota.</p>
                <h3>Istemal</h3>
                <x-docs.steps>
                    <li><a href="{{ route('contents.create') }}" wire:navigate>Content → New content</a> kholein aur type chunein.</li>
                    <li>Text / Caption ke saath <strong>✨ AI se likho</strong> dabayein.</li>
                    <li>Topic likhein (jitni tafseel, utna behtar: naam, offer, tareekh, link), zaban (English / اردو / Roman Urdu / हिन्दी), andaaz aur lambai chunein, emojis / hashtags on-off karein.</li>
                    <li><strong>Draft banao</strong>. 2-core server par 10–60 second lagte hain. Dialog band bhi kar dein to draft khud text box mein aa jata hai.</li>
                    <li>Draft parhein, ghalat baat theek karein, phir <strong>Save content</strong>.</li>
                </x-docs.steps>
                <x-docs.callout type="warning">Chhota model (1–1.5B) English mein theek likhta hai, Urdu mein kamzor ho sakta hai, aur kabhi apni taraf se baat bana deta hai. Is liye har draft post se pehle zaroor parhein.</x-docs.callout>
                <h3>Server par ek dafa setup (Super Admin)</h3>
                <p>Server par (root) yeh command Ollama ka official installer chalati hai (~1–2 GB download), aur use sirf <code>127.0.0.1</code> par, ek waqt mein ek request, aur 5 minute khaali rehne par RAM khali karne ke liye set karti hai. Phir model (~1 GB) download karke ek test jawab leti hai:</p>
                <x-docs.code>(
set -e
curl -fsSL https://ollama.com/install.sh | sh
mkdir -p /etc/systemd/system/ollama.service.d
cat > /etc/systemd/system/ollama.service.d/override.conf &lt;&lt;'EOF'
[Service]
Environment="OLLAMA_HOST=127.0.0.1:11434"
Environment="OLLAMA_NUM_PARALLEL=1"
Environment="OLLAMA_MAX_LOADED_MODELS=1"
Environment="OLLAMA_KEEP_ALIVE=5m"
EOF
systemctl daemon-reload
systemctl restart ollama
sleep 3
ollama pull qwen2.5:1.5b
ollama run qwen2.5:1.5b "Write one short friendly Telegram post about Monday motivation."
)</x-docs.code>
                <x-docs.steps>
                    <li>Command ke aakhir mein model ka chhota sa jawab aaye to AI server tayyar hai.</li>
                    <li>Panel mein <a href="{{ route('settings.index', ['tab' => 'ai']) }}" wire:navigate>Settings → AI (offline)</a> → <strong>AI drafts on karein</strong> → Save → <strong>AI server check karein</strong> ("is installed" aana chahiye).</li>
                    <li><a href="{{ route('system-health') }}" wire:navigate>System Health</a> mein <strong>Local AI: Healthy</strong> dikhega.</li>
                </x-docs.steps>
                <p>Doosra model aazmana ho: server par <code>ollama pull &lt;naam&gt;</code>, phir Settings → AI mein model ka naam badal dein. <code>qwen2.5:1.5b</code> (~1 GB RAM) English mein achha aur tez hai; Urdu / Roman Urdu / Hindi behtar chahiye to <code>qwen2.5:3b</code> (~2 GB RAM, takreeban do guna dheere) aazmayein. 8 GB RAM wale server par is se bara model na lagayein. "Thinking" models (jaise qwen3 ke reasoning versions) post ki jagah apni soch likhte hain; panel unhein pakar kar error dikhata hai.</p>
            </x-docs.section>

            {{-- Quick Send --}}
            <x-docs.section id="quick-send" title="Quick Send (foran message)" icon="send">
                <p>Jab koi message <strong>abhi</strong> bhejna ho to campaign banane ki zaroorat nahi. Bot + channel/group ka joda ek dafa save karein, phir sirf likhein aur Send dabayein.</p>
                <x-docs.steps>
                    <li>Sidebar → <a href="{{ route('quick-send') }}" wire:navigate>Automation → Quick Send</a> → <strong>New target</strong>.</li>
                    <li>Bot chunein, phir us bot ka channel ya group. Naam khud bhar jata hai (badal sakte hain). Chahein to formatting (HTML), silent, link preview band, ya forward se hifazat on karein → <strong>Save target</strong>.</li>
                    <li>Bayen taraf <strong>Send to</strong> list se target chunein, message likhein aur <strong>Send</strong> dabayein (ya <span class="kbd-pill">Ctrl</span> + <span class="kbd-pill">Enter</span>).</li>
                    <li>Neeche foran <strong>Sent</strong> (Telegram message number ke saath) ya ghalti ki wajah dikhti hai. Haal ke messages usi page par, aur sab <a href="{{ route('logs.index') }}" wire:navigate>Posting Logs</a> mein.</li>
                </x-docs.steps>
                <x-docs.callout type="info">Quick Send bhi queue ke zariye jata hai (aam taur par 1–2 second), Telegram ki flood limit ka khayal rakhta hai aur plan ki daily / weekly / monthly posting limit mein ginta hai. Network ghalti par khud dobara nahi bhejta, taake message do baar na jaye.</x-docs.callout>
            </x-docs.section>

            {{-- Welcome DMs --}}
            <x-docs.section id="auto-queue" title="Auto Queue (ek dafa set, library khud post ho)" icon="refresh">
                <p>Channel aur posting ke times <strong>sirf ek dafa</strong> set karein. Har time par Content Library ka <strong>agla content</strong> khud us channel par chala jata hai. Library mein naya content daalein to woh khud line mein lag jata hai; saara content ek baar post ho jaye to <strong>shuru se dobara</strong> (Round 2, 3…).</p>
                <x-docs.steps>
                    <li>Sidebar → <a href="{{ route('auto-queues.index') }}" wire:navigate>Automation → Auto Queue</a> → <strong>New auto queue</strong>.</li>
                    <li>Bot aur channel / group chunein.</li>
                    <li>Posting times likhein (jaise 09:00, 14:00, 20:00; <strong>Add time</strong> se aur), din chunein aur timezone check karein.</li>
                    <li>Chahein to sirf ek tag ka content chunein (<strong>Which content</strong>), aur tarteeb: purana pehle, naya pehle ya random → <strong>Save</strong>.</li>
                </x-docs.steps>
                <p>Card par <strong>Coming up</strong> mein agle posts ka waqt aur content dikhta hai, aur <strong>Last posts</strong> mein natija. <strong>Post next now</strong> agla content foran bhej deta hai (regular times wahi rehte hain). <strong>Pause</strong> se sab ruk jata hai.</p>
                <x-docs.callout type="info">Sirf <strong>active</strong> content post hota hai. Kisi content ko line se nikalna ho to Content Library mein use deactivate kar dein. Agar server kisi waqt band raha ho to 15 minute se zyada late post nahi kiya jata (ghalat waqt par post na ho), woh time skip hota hai aur card par wajah likhi aati hai. Plan ki posting limit poori ho to bhi time skip hota hai.</x-docs.callout>
            </x-docs.section>

            <x-docs.section id="member-import" title="Member import (list se members lana)" icon="users">
                <x-docs.callout type="warning" title="Telegram ka qanoon">Bot kisi ko group / channel mein <strong>add nahi kar sakta</strong>. Personal accounts se bulk add Telegram ke qawaneen ke khilaf hai aur accounts ban ho jate hain, is liye yeh panel aisa kabhi nahi karta. Har shakhs <strong>khud join request</strong> bhejta hai, aur sirf list walon ki request khud approve hoti hai.</x-docs.callout>
                <x-docs.steps>
                    <li>Bot ko group / channel ka admin banayein: “Invite users via link” aur (groups mein) members dekhne ka right.</li>
                    <li>Sidebar → <a href="{{ route('member-imports.index') }}" wire:navigate>Telegram → Member Import</a> → group / channel chunein.</li>
                    <li>CSV, TXT ya Excel (.xlsx) file upload karein: har line mein <span class="kbd-pill">@username</span>, <span class="kbd-pill">t.me/username</span> ya numeric user ID. “username” / “user_id” header bhi chalta hai. <a href="{{ route('member-imports.sample') }}">Sample file</a>.</li>
                    <li>Options: <strong>Send a private invite</strong> (sirf un ko jinhon ne bot Start kiya) aur <strong>Approve join requests from people on this list</strong>. Phir <strong>Upload & start</strong>.</li>
                    <li>Report page par progress live chalti hai. Har shakhs ka status aur wajah dikhti hai, status par click karke filter, aur <strong>Export CSV</strong>.</li>
                    <li>“Waiting for request” walon ko join-request link (WhatsApp, e-mail, website par) bhejein. Woh request bhejte hi khud approve ho kar <strong>Added</strong> ho jate hain. Baad mein <strong>Check again</strong> se dobara check karein.</li>
                </x-docs.steps>
                <table>
                    <thead><tr><th>Status</th><th>Matlab</th></tr></thead>
                    <tbody>
                        <tr><td>Added</td><td>Join request approve ho gayi ya woh join ho gaye.</td></tr>
                        <tr><td>Invited</td><td>Bot Start kiya hua tha, private invite chala gaya.</td></tr>
                        <tr><td>Waiting for request</td><td>Bot un tak nahi pahunch sakta; link se request aate hi approve.</td></tr>
                        <tr><td>Already member</td><td>Pehle se group mein.</td></tr>
                        <tr><td>Skipped</td><td>Ghalat entry, duplicate, phone number, ya group se banned.</td></tr>
                        <tr><td>Failed</td><td>Telegram ki ghalti (wajah saath likhi hoti hai).</td></tr>
                    </tbody>
                </table>
            </x-docs.section>

            <x-docs.section id="welcome" title="Welcome DMs (naye members ko private message)" icon="chat">
                <x-docs.callout type="warning" title="Telegram ka qanoon">Koi bot kisi ko khud se DM nahi kar sakta. DM sirf un logon ko ja sakta hai jo <strong>join-request link</strong> se join karein (request aane ke 5 minute tak), ya jinhon ne bot ko <strong>Start</strong> kiya ho. Aam public link se aane walon ko koi bhi bot message nahi kar sakta.</x-docs.callout>
                <x-docs.steps>
                    <li>Bot ko channel / group ka admin banayein aur <strong>Invite users via link</strong> ka right on karein (auto-approve ke liye).</li>
                    <li>Sidebar → <a href="{{ route('welcome.index') }}" wire:navigate>Telegram → Welcome DMs</a> → channel ke saath <strong>Edit</strong>.</li>
                    <li><strong>Send a welcome DM to new members</strong> on karein, message likhein. <span class="kbd-pill">{first_name}</span>, <span class="kbd-pill">{username}</span>, <span class="kbd-pill">{chat_title}</span> waghera member ke naam se badal jate hain. Chahein to ek link button (jaise “Visit our website”) jorein. Daayein taraf preview dikhta hai.</li>
                    <li><strong>Approve join requests automatically</strong> on karein to welcome ke baad bot khud member ko andar le leta hai. Save par bot khud “Connected” ho jata hai (website https:// par honi chahiye).</li>
                    <li><strong>Create link</strong> dabayein aur yeh join-request link apne posts, ads aur website par share karein (purane public link ki jagah).</li>
                </x-docs.steps>
                <p>Har welcome DM <a href="{{ route('logs.index') }}" wire:navigate>Posting Logs</a> mein “Welcome DM” ke naam se aata hai aur plan ki posting limit mein ginta hai. Limit poori ho to DM chhor diya jata hai magar auto-approve phir bhi hota hai. Jis user ne bot block kiya ho use dobara koshish nahi ki jati.</p>
                <x-docs.callout type="info">Bot ki updates is panel par aati hain (webhook). Agar yahi bot kisi aur program mein bhi chal raha ho (getUpdates / webhook) to woh program updates lena band kar dega, is liye welcome ke liye alag bot behtar hai.</x-docs.callout>
            </x-docs.section>

            {{-- 7. Campaign wizard --}}
            <x-docs.section id="campaign-wizard" title="7. Campaign banana (10 steps)" icon="bolt">
                <p>Campaign batati hai: <strong>kaunsa bot</strong>, <strong>kis chat mein</strong>, <strong>kaunsa content kis tarteeb se</strong>, aur <strong>kab</strong>. <a href="{{ route('campaigns.create') }}" wire:navigate>Campaigns → New campaign</a> kholein. Upar wali patti se kisi bhi pichle step par wapas ja sakte hain.</p>
                <table>
                    <thead><tr><th>Step</th><th>Kya karna hai</th></tr></thead>
                    <tbody>
                        <tr><td>1. Campaign info</td><td>Naam (jaise "Daily Videos") aur description.</td></tr>
                        <tr><td>2. Bot</td><td>Jis bot se posts jayengi.</td></tr>
                        <tr><td>3. Destination</td><td>Us bot ka channel / group. Yahin <strong>Test mode</strong> on karke test chat chun sakte hain.</td></tr>
                        <tr><td>4. Select content</td><td>Search karke <strong>Add</strong> dabayein. Ek hi content do dafa bhi add ho sakta hai.</td></tr>
                        <tr><td>5. Arrange</td><td>Handle (do lakeeron wala icon) se <strong>drag & drop</strong>, ya teer se upar / neeche. Duplicate aur remove bhi yahin.</td></tr>
                        <tr><td>6. Trigger</td><td>Posts kab jayengi: Manual, Scheduled, Interval, Fixed times, Daily ya Weekly (section 8). Sath mein Sequential ya Random order.</td></tr>
                        <tr><td>7. Schedule</td><td>Interval / waqt / din, <strong>timezone</strong>, start aur end date.</td></tr>
                        <tr><td>8. Retry & failure</td><td>Post fail ho to kya karna hai, kitni dafa retry, aur aakhri item ke baad repeat (section 10).</td></tr>
                        <tr><td>9. Review</td><td>Poori summary aur aane wali posts ke waqt.</td></tr>
                        <tr><td>10. Activate</td><td><strong>Save as draft</strong> (baad mein) ya <strong>Save & activate</strong> (foran shuru).</td></tr>
                    </tbody>
                </table>
                <x-docs.callout type="tip">Draft campaign kuch post nahi karti. Activate karne se pehle system check karta hai ke bot aur chat active hain, content maujood hai aur schedule poora hai. Koi kami ho to campaign page par peela box saaf batata hai.</x-docs.callout>
            </x-docs.section>

            {{-- 8. Triggers --}}
            <x-docs.section id="triggers" title="8. Trigger types" icon="calendar">
                <p>Har campaign ek waqt mein <strong>ek item</strong> post karti hai. Trigger batata hai ke agla item kab jaye.</p>
                <table>
                    <thead><tr><th>Trigger</th><th>Kaise chalta hai</th><th>Misaal</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Manual</strong></td><td>Khud kuch nahi bhejta; har <strong>Run now</strong> par agla item.</td><td>Jab aap chahein tab post</td></tr>
                        <tr><td><strong>Scheduled</strong></td><td>Har item ki apni pakki tareekh aur waqt.</td><td>Video 1: 10 Oct 10:00, Video 2: 12 Oct 18:30</td></tr>
                        <tr><td><strong>Interval</strong></td><td>Start time se har N minute / ghante baad ek item. 1 minute se 24 ghante tak, ya custom.</td><td>09:00 se har 30 minute: 09:00, 09:30, 10:00…</td></tr>
                        <tr><td><strong>Fixed times</strong></td><td>Roz kai muqarrar waqt.</td><td>09:00, 12:00, 15:00, 18:00, 21:00</td></tr>
                        <tr><td><strong>Daily</strong></td><td>Roz ek waqt.</td><td>Har roz 10:00</td></tr>
                        <tr><td><strong>Weekly</strong></td><td>Chune hue dinon par ek waqt.</td><td>Mon, Wed, Fri 19:00</td></tr>
                    </tbody>
                </table>
                <ul>
                    <li>Saare waqt campaign ke <strong>timezone</strong> mein samjhe jate hain (default <code>Asia/Karachi</code>).</li>
                    <li><strong>Start date</strong> khaali ho to campaign activate karte hi shuru ho jati hai. <strong>End date</strong> ke baad koi post nahi jati.</li>
                    <li>Server kuch der band raha ho to chhoot jane wali posts ek sath nahi bhejta; agle waqt se aage chalta hai.</li>
                    <li>Interval campaign mein <strong>Run now</strong> ke baad agli post usi waqt se ek interval baad jati hai.</li>
                    <li><strong>Random</strong> order mein har cycle mein har item sirf ek dafa jata hai, aur ek hi content lagataar do dafa nahi jata.</li>
                </ul>
            </x-docs.section>

            {{-- 9. Campaign run --}}
            <x-docs.section id="campaign-run" title="9. Campaign chalana aur control karna" icon="play">
                <p>Campaign ke naam par click karne se details page khulta hai. Chalti campaign ka page har 4 second mein khud update hota hai.</p>
                <h3>Upar wale buttons</h3>
                <table>
                    <thead><tr><th>Button</th><th>Kaam</th><th>Kab dikhta hai</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Activate</strong></td><td>Draft campaign shuru karta hai.</td><td>Draft</td></tr>
                        <tr><td><strong>Run now</strong></td><td>Schedule ka intezar kiye baghair agla item foran bhejta hai.</td><td>Scheduled, Running, Paused</td></tr>
                        <tr><td><strong>Pause</strong></td><td>Nayi posts rok deta hai. Jo post us waqt ja rahi ho woh poori ho jati hai.</td><td>Scheduled, Running</td></tr>
                        <tr><td><strong>Resume</strong></td><td>Wahin se aage chalata hai jahan ruka tha; item #1 se dobara <em>nahi</em>.</td><td>Paused, Failed</td></tr>
                        <tr><td><strong>Stop</strong></td><td>Campaign band (status "Stopped"). Baad mein Restart ho sakti hai.</td><td>Scheduled, Running, Paused, Failed</td></tr>
                        <tr><td><strong>Restart</strong></td><td>Saare items pending karke item #1 se nayi cycle. Purane logs mehfooz.</td><td>Draft ke ilawa sab</td></tr>
                        <tr><td><strong>Edit</strong></td><td>Wizard dobara kholta hai. Running campaign edit karne se pehle Pause karein.</td><td>Running ke ilawa</td></tr>
                        <tr><td><strong>Logs</strong></td><td>Sirf isi campaign ke Posting Logs.</td><td>Hamesha</td></tr>
                    </tbody>
                </table>
                <h3>Statuses</h3>
                <table>
                    <thead><tr><th>Status</th><th>Matlab</th></tr></thead>
                    <tbody>
                        <tr><td>Draft</td><td>Bani hui hai, abhi activate nahi hui.</td></tr>
                        <tr><td>Scheduled</td><td>Active hai, start date ka intezar kar rahi hai.</td></tr>
                        <tr><td>Running</td><td>Chal rahi hai.</td></tr>
                        <tr><td>Paused</td><td>Aap ne roki hai.</td></tr>
                        <tr><td>Completed</td><td>Saare items post ho gaye (aur repeat band hai).</td></tr>
                        <tr><td>Failed</td><td>"Stop campaign on failure" ki wajah se ruki; wajah peele / laal box mein.</td></tr>
                        <tr><td>Stopped</td><td>Aap ne Stop kiya.</td></tr>
                    </tbody>
                </table>
                <h3>Content sequence (neeche list)</h3>
                <ul>
                    <li><strong>Drag & drop</strong> se tarteeb badlein; ya <strong>⋮ menu</strong> → Move up / Move down / Duplicate / Preview / Remove.</li>
                    <li><strong>Skip</strong> (pending item par): woh item chhor kar agla chalta hai. Misaal: video #5 kharab hai → Skip → video #6.</li>
                    <li><strong>Retry</strong> (failed / skipped item par): item dobara pending ho jata hai aur agle run (ya Run now) par jata hai.</li>
                    <li><strong>Add content</strong>: list ke aakhir mein naye items.</li>
                    <li>Neela highlight wala item <strong>agla</strong> post hone wala hai. Har item par sent time, Telegram message ID ya error dikhta hai.</li>
                </ul>
                <p>Upar ke cards mein progress (jaise <strong>12 / 50</strong>), sent / pending / failed / skipped ginti, aakhri post, agli post aur agla content dikhta hai.</p>
            </x-docs.section>

            {{-- 10. Failures --}}
            <x-docs.section id="failures" title="10. Retry, failure aur repeat" icon="refresh">
                <h3>Post fail ho to (If a post fails)</h3>
                <table>
                    <thead><tr><th>Option</th><th>Kya hota hai</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Retry, then continue</strong> (default)</td><td>Waqti errors (network, timeout, Telegram server) par "Maximum retries" tak dobara koshish, har dafa delay double. Phir item failed aur campaign aage.</td></tr>
                        <tr><td><strong>Stop campaign on failure</strong></td><td>Wahi retries; phir bhi fail ho to campaign <strong>Failed</strong> ho kar ruk jati hai aur admins ko alert jata hai.</td></tr>
                        <tr><td><strong>Continue after failure</strong></td><td>Koi retry nahi; item failed aur campaign aage.</td></tr>
                        <tr><td><strong>Retry until successful</strong></td><td>Jab tak post na jaye koshish jaari (delay barhta jata hai, zyada se zyada 1 ghanta). Rokna ho to item par <strong>Skip</strong>.</td></tr>
                    </tbody>
                </table>
                <ul>
                    <li>Pakke errors (chat not found, bot admin nahi, ghalat media, ghalat token) par retry nahi hota, kyunke dobara koshish se bhi kuch nahi badlega.</li>
                    <li>Telegram "Too many requests" (flood control) bole to bot utni hi der rukta hai jitni Telegram kehta hai; is se retry ki ginti kam nahi hoti.</li>
                    <li>Server crash ho jaye aur system ko yaqeen na ho ke post gayi ya nahi, to item <strong>failed</strong> dikhata hai aur khud dobara <em>nahi</em> bhejta, taake channel mein double post na jaye. Channel check karke zaroorat ho to <strong>Retry</strong> karein.</li>
                </ul>
                <h3>Aakhri item ke baad (Repeat)</h3>
                <table>
                    <thead><tr><th>Option</th><th>Kya hota hai</th></tr></thead>
                    <tbody>
                        <tr><td>Stop (mark completed)</td><td>Campaign Completed.</td></tr>
                        <tr><td>Repeat immediately</td><td>Foran item #1 se nayi cycle, usi schedule par.</td></tr>
                        <tr><td>Repeat daily / weekly</td><td>Agle din / hafte, pehli cycle ke start time par dobara.</td></tr>
                        <tr><td>Repeat indefinitely</td><td>Hamesha chalti rahe.</td></tr>
                    </tbody>
                </table>
                <p>Immediately / daily / weekly ke sath <strong>Number of repeats</strong> de sakte hain; khaali = kabhi khatam nahi.</p>
            </x-docs.section>

            {{-- 11. Test mode --}}
            <x-docs.section id="test-mode" title="11. Test mode" icon="beaker">
                <p>Test mode on ho to campaign asal channel ki bajaye ek <strong>test chat</strong> mein post karti hai. Is se content, tarteeb, captions, media aur bot sab check ho jate hain.</p>
                <x-docs.steps>
                    <li>Ek private group ya channel banayein, bot add karein, aur Channels / Groups mein <strong>Use as a test chat</strong> ke sath add karein.</li>
                    <li>Wizard ke step 3 mein <strong>Test mode</strong> on karke yeh chat chunein. Test chat usi bot ki honi chahiye.</li>
                    <li>Campaign chalayein. Campaign page par jamni patti "Test mode is on" dikhati hai aur logs mein har post par <code>test</code> likha hota hai.</li>
                    <li>Sab theek ho to <strong>Edit</strong> karke Test mode off karein aur <strong>Restart</strong>.</li>
                </x-docs.steps>
            </x-docs.section>

            {{-- 12. Schedules --}}
            <x-docs.section id="schedules" title="12. Schedules" icon="calendar">
                <p><a href="{{ route('schedules.index') }}" wire:navigate>Automation → Schedules</a> saari campaigns ki aane wali posts ek jagah dikhata hai: har campaign ka trigger, timezone aur agle 4 waqt. Active / Paused / All aur bot ke hisaab se filter kar sakte hain.</p>
            </x-docs.section>

            {{-- 13. Monitoring --}}
            <x-docs.section id="monitoring" title="13. Logs aur monitoring" icon="list">
                <h3>Posting Logs</h3>
                <p><a href="{{ route('logs.index') }}" wire:navigate>Monitoring → Posting Logs</a> mein har koshish ka record hai: waqt, content, campaign, chat, status (Pending / Processing / Sent / Failed / Skipped), attempt aur error. Campaign, bot, destination, status aur tareekh se filter karein. <strong>Eye</strong> icon par Telegram ka poora jawab aur message ID milti hai. Restart karne se purane logs delete nahi hote.</p>
                <h3>Failed Jobs</h3>
                <p><a href="{{ route('failed-jobs.index') }}" wire:navigate>Failed Jobs</a> mein woh background jobs hain jo achanak crash hui (jaise database thori der ke liye band). Aam Telegram errors yahan nahi, Posting Logs mein hote hain. Har job ko dekh, <strong>Retry</strong> ya <strong>Delete</strong> kar sakte hain. Retry se koi post do dafa nahi jati.</p>
                <h3>Audit Logs</h3>
                <p><a href="{{ route('audit-logs.index') }}" wire:navigate>Audit Logs</a> batata hai kis user ne kya kiya, kab aur kis IP se: login / logout, bot / channel / content / campaign ki tabdeeliyan, settings waghera. Passwords aur tokens kabhi record nahi hote.</p>
            </x-docs.section>

            {{-- 14. Notifications --}}
            <x-docs.section id="notifications" title="14. Notifications" icon="bell">
                <p>Top bar ki <strong>ghanti</strong> par laal number na-parhe alerts batata hai. Alerts in mauqon par aate hain:</p>
                <ul>
                    <li>Campaign started, completed ya failed</li>
                    <li>Bot disconnected (token ghalat ya revoke)</li>
                    <li>Telegram permission problem (bot admin nahi raha, chat nahi mili)</li>
                    <li>Lagataar kai posts fail (ginti Settings → Notifications mein)</li>
                </ul>
                <p>Alert par click karne se mutalliqa page khulta hai. <a href="{{ route('notifications') }}" wire:navigate>Saare notifications</a> page par mark read / delete. Settings mein e-mail alerts bhi on ho sakte hain.</p>
            </x-docs.section>

            {{-- 15. Users --}}
            <x-docs.section id="users" title="15. Users aur Roles" icon="users">
                <h3>Users</h3>
                <x-docs.steps>
                    <li><a href="{{ route('users.index') }}" wire:navigate>Users → Users</a> → <strong>Add user</strong>: naam, e-mail, role, status aur password.</li>
                    <li><strong>⋮ menu</strong> se: naya password set karna, reset link e-mail karna, disable / enable, delete.</li>
                </x-docs.steps>
                <p>Disable kiya gaya user foran har jagah se logout ho jata hai aur us ke API tokens band ho jate hain. Aap khud ko disable / delete nahi kar sakte, aur aakhri active Super Admin ko bhi nahi.</p>
                <h3>Roles</h3>
                <table>
                    <thead><tr><th>Role</th><th>Ikhtiyar</th></tr></thead>
                    <tbody>
                        <tr><td><strong>Super Admin</strong></td><td>Sab kuch, users, roles aur settings samet.</td></tr>
                        <tr><td><strong>Admin</strong></td><td>Har account ke bots, channels, content, campaigns, logs, failed jobs, system health aur purchase requests. Users, roles, settings, plans aur audit log nahi.</td></tr>
                        <tr><td><strong>Customer</strong></td><td>Register karne wale log: sirf apne bots, channels, content aur campaigns, apne plan ki limits ke andar.</td></tr>
                    </tbody>
                </table>
                <p>Jis role mein <strong>“Staff: all accounts”</strong> permission ho woh staff hai: sab accounts ka data dekhta hai aur us par koi plan limit nahi. Baqi har role customer jaisa hai.</p>
                <p><a href="{{ route('roles.index') }}" wire:navigate>Roles</a> page par <strong>New role</strong> se apna role bana kar checkboxes se permissions chunein (jaise sirf campaigns dekhne wala "Viewer"). Sidebar har user ko sirf woh pages dikhata hai jin ki ijazat hai.</p>
            </x-docs.section>

            {{-- Accounts, plans and billing --}}
            <x-docs.section id="billing" title="Accounts, plans aur billing" icon="credit-card">
                <p>Koi bhi <a href="{{ route('register') }}">Create account</a> se apna account bana sakta hai: naam, e-mail, Mobile/WhatsApp number aur password. Account foran <strong>Free</strong> plan par chal parta hai. Har customer sirf apne bots, channels, groups, content aur campaigns dekhta hai; doosre accounts ka data kabhi nahi.</p>
                <h3>Plan ki limits</h3>
                <table>
                    <thead><tr><th>Limit</th><th>Kya hota hai</th></tr></thead>
                    <tbody>
                        <tr><td>Bots / Channels / Groups / Campaigns / Content</td><td>Limit poori ho to naya add nahi hota; message mein upgrade ka kaha jata hai. Purana kuch delete nahi hota.</td></tr>
                        <tr><td>Posts per day / week / month</td><td>Limit poori ho to post <strong>ruk jati hai</strong> aur reset hote hi (agla din / hafta / mahina, aap ke timezone mein) <strong>khud chal parti hai</strong>. Aap ko ek notification milti hai.</td></tr>
                        <tr><td>Shortest interval</td><td>Interval campaign is se zyada tez post nahi kar sakti (Free: 30 minute).</td></tr>
                        <tr><td>Media uploads / AI / API</td><td>Sirf un plans mein jin mein shamil hon.</td></tr>
                    </tbody>
                </table>

                <h3>Plan khareedna (customer)</h3>
                <x-docs.steps>
                    <li>Sidebar → <a href="{{ route('billing.index') }}" wire:navigate>Billing & plans</a>: apna plan, istemal aur plans dekhein. Plan par <strong>Choose</strong> dabayein.</li>
                    <li>Payment method chunein (jaise USDT, bank). Address <strong>Copy</strong> karein, raqam (USD) bhejein.</li>
                    <li>Transaction ID / reference likhein, chahein to screenshot lagayein, apne contact details check karein aur <strong>“maloomat sahi aur valid hain”</strong> par tick karke <strong>Send purchase request</strong>.</li>
                    <li>Request <strong>Pending</strong> rehti hai jab tak admin payment check kare. Approve hote hi plan 1 mahine ke liye chal parta hai aur notification aati hai.</li>
                    <li>Renew: wahi plan dobara khareedne se expiry mein ek mahina aur jur jata hai. Expiry se 3 din pehle yaad-dihani aati hai; expiry par account Free par aa jata hai.</li>
                </x-docs.steps>

                <h3>Requests sambhalna (admin)</h3>
                <x-docs.steps>
                    <li><a href="{{ route('admin.payment-methods.index') }}" wire:navigate>Sales → Payment methods</a>: naam, address / account number, account holder, hidayat aur QR image add karein.</li>
                    <li><a href="{{ route('admin.plans.index') }}" wire:navigate>Sales → Plans</a>: keemat (USD / mahina), har limit (khaali = unlimited) aur features. Plan hide kar sakte hain; jo plan istemal mein ho woh delete nahi hota.</li>
                    <li>Nayi request par notification aati hai aur sidebar mein <strong>Purchase requests</strong> par number dikhta hai. <strong>Review</strong> kholein: customer ke details, raqam, address, transaction ID aur screenshot.</li>
                    <li>Zaroorat ho to <strong>E-mail</strong>, <strong>Call</strong> ya <strong>WhatsApp</strong> button se seedha rabta karein.</li>
                    <li>Payment mil gayi ho to <strong>Approve & activate</strong> (expiry khud ban jati hai), warna wajah likh kar <strong>Reject</strong>. Customer ko dono soorat mein notification jati hai.</li>
                    <li>Haath se plan dena (gift / correction): <a href="{{ route('users.index') }}" wire:navigate>Users</a> → ⋮ → <strong>Change plan</strong>.</li>
                </x-docs.steps>
                <x-docs.callout type="info">Settings → <strong>Registration & billing</strong>: sign-up on/off, aur Name / E-mail / Mobile / WhatsApp har field ko Required, Optional ya Hidden karein. Wahin checkout ki hidayat aur support WhatsApp / e-mail bhi.</x-docs.callout>
            </x-docs.section>

            {{-- 16. Settings --}}
            <x-docs.section id="settings" title="16. Settings" icon="cog">
                <table>
                    <thead><tr><th>Tab</th><th>Kya badal sakte hain</th></tr></thead>
                    <tbody>
                        <tr><td>General</td><td>App ka naam, logo, display timezone, nayi campaigns ka default timezone.</td></tr>
                        <tr><td>Telegram</td><td>API timeout, default retries aur delay, per-bot aur per-chat speed limit, http:// media ki ijazat, test message ka text.</td></tr>
                        <tr><td>Queue</td><td>Sirf maloomat (server ki <code>.env</code> se aati hai).</td></tr>
                        <tr><td>Storage</td><td>Upload ki gayi files kahan rahen: server (Local) ya S3-compatible storage.</td></tr>
                        <tr><td>Notifications</td><td>E-mail alerts on / off, kitni lagataar failures par alert.</td></tr>
                    </tbody>
                </table>
                <p>Tabdeeli foran lagu hoti hai, panel aur background workers dono par.</p>
            </x-docs.section>

            {{-- 17. Health --}}
            <x-docs.section id="health" title="17. System Health" icon="heart">
                <p><a href="{{ route('system-health') }}" wire:navigate>System → System Health</a> batata hai ke posting ke liye zaroori har cheez chal rahi hai ya nahi:</p>
                <table>
                    <thead><tr><th>Check</th><th>Failed ho to</th></tr></thead>
                    <tbody>
                        <tr><td>Database</td><td>MySQL band hai ya <code>.env</code> mein ghalat password.</td></tr>
                        <tr><td>Redis</td><td>Redis band hai ya password ghalat.</td></tr>
                        <tr><td>Queue workers</td><td>Supervisor ka worker nahi chal raha: koi post nahi jayegi.</td></tr>
                        <tr><td>Scheduler</td><td>Cron task nahi chal raha: scheduled posts nahi jayengi.</td></tr>
                        <tr><td>Telegram API</td><td>Server Telegram tak nahi pahunch pa raha.</td></tr>
                        <tr><td>Storage</td><td>Files save nahi ho rahi (permissions).</td></tr>
                    </tbody>
                </table>
                <p><strong>Run recovery</strong> crash ke baad atke hue items theek karta hai (yeh har minute khud bhi chalta hai).</p>
            </x-docs.section>

            {{-- 18. FAQ --}}
            <x-docs.section id="faq" title="18. Masail aur hal" icon="warning">
                <table>
                    <thead><tr><th>Masla / error</th><th>Hal</th></tr></thead>
                    <tbody>
                        <tr><td>Campaign active hai magar post nahi ja rahi</td><td>System Health dekhein: Queue workers aur Scheduler dono Healthy hone chahiyein. Campaign page par "Next post" ka waqt bhi check karein.</td></tr>
                        <tr><td>"The bot token is invalid or was revoked"</td><td>@BotFather se naya token (<code>/revoke</code> ya <code>/token</code>) le kar bot edit karein.</td></tr>
                        <tr><td>"Chat not found"</td><td>Chat ID / username ghalat hai, ya bot chat mein add nahi. Private chat ke liye <code>-100…</code> wali ID.</td></tr>
                        <tr><td>"The bot lacks permissions" / not an administrator</td><td>Channel → Administrators → bot → <strong>Post messages</strong> on. Phir Test message.</td></tr>
                        <tr><td>"Telegram could not use the media"</td><td>URL public, https aur seedha file ka ho; size hadd ke andar. Content form mein <strong>Check URL</strong>, ya file upload karein.</td></tr>
                        <tr><td>12 MB se bari file upload nahi hoti</td><td>Abhi panel ki upload limit 12 MB hai. Bari video ke liye External URL istemal karein.</td></tr>
                        <tr><td>Item par "Delivery was interrupted"</td><td>Post ke dauran server ruk gaya tha. Channel dekhein; post na ho to item par <strong>Retry</strong>.</td></tr>
                        <tr><td>Run now: "An item is being delivered right now"</td><td>Pichli post abhi ja rahi hai; kuch second baad dobara.</td></tr>
                        <tr><td>Activate nahi hoti</td><td>Campaign page ka peela box kami batata hai: bot disabled, chat inactive, koi content nahi, ya schedule adhoora.</td></tr>
                        <tr><td>Content delete nahi hota</td><td>Woh kisi adhoori campaign mein hai; pehle sequence se hatayein ya campaign stop karein.</td></tr>
                    </tbody>
                </table>
            </x-docs.section>

        </div>
    </div>
</div>
