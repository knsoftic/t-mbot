<div>
    <x-page-header title="API Docs" description="Mobile app ya doosre system ke liye REST API. Har endpoint wahi permissions maangta hai jo admin panel.">
        <x-slot:actions>
            <a href="{{ route('profile') }}" wire:navigate class="btn btn-primary"><x-icon name="key" class="size-4" /> API token banayein</a>
            <a href="{{ route('help.guide') }}" wire:navigate class="btn btn-secondary"><x-icon name="info" class="size-4" /> User Guide</a>
        </x-slot:actions>
    </x-page-header>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
        <aside class="lg:col-span-1">
            <nav class="card p-3 lg:sticky lg:top-24 lg:max-h-[calc(100vh-8rem)] lg:overflow-y-auto">
                <div class="px-2 pb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">API reference</div>
                @foreach ($sections as $id => $label)
                    <a href="#{{ $id }}" class="block rounded-lg px-2 py-1.5 text-sm text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white">{{ $label }}</a>
                @endforeach
            </nav>
        </aside>

        <div class="space-y-6 lg:col-span-3">

            {{-- Overview --}}
            <x-docs.section id="overview" title="Overview" icon="link">
                <p>Base URL:</p>
                <x-docs.code title="Base URL">{{ $base }}</x-docs.code>
                <p>Har request JSON hai aur har jawab JSON. 3 qadam mein shuru karein:</p>
                <x-docs.steps>
                    <li>Token lein: <strong>Profile → API tokens</strong> se, ya neeche diye <code>POST /auth/token</code> se.</li>
                    <li>Har request mein headers bhejein: <code>Authorization: Bearer &lt;token&gt;</code> aur <code>Accept: application/json</code>.</li>
                    <li>Pehli call karke dekhein:</li>
                </x-docs.steps>
                <x-docs.code>curl {{ $base }}/me \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"</x-docs.code>
                <x-docs.callout type="warning">Token password jaisa hai. Usay app ke code ya public repo mein na rakhein; kho jaye to Profile se <strong>Revoke</strong> karein.</x-docs.callout>
            </x-docs.section>

            {{-- Authentication --}}
            <x-docs.section id="authentication" title="Authentication" icon="key">
                <p>API Laravel Sanctum ke <strong>personal access tokens</strong> istemal karti hai. Token us user ki saari permissions rakhta hai jis ne usay banaya. User disable ho to us ke saare tokens band ho jate hain.</p>
                <table>
                    <thead><tr><th>Tareeqa</th><th>Kab</th></tr></thead>
                    <tbody>
                        <tr><td>Admin panel → Profile → API tokens → Create token</td><td>Server-to-server integration, scripts</td></tr>
                        <tr><td><code>POST /auth/token</code> (e-mail + password)</td><td>Mobile app login screen</td></tr>
                    </tbody>
                </table>
                <x-docs.code>curl -X POST {{ $base }}/auth/token \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"your-password","device_name":"Android app"}'</x-docs.code>
                <x-docs.code title="201 Created">{
  "token": "3|kWm0x...",
  "token_type": "Bearer",
  "user": { "id": 1, "name": "Admin", "email": "admin@example.com", "role": "super-admin" }
}</x-docs.code>
            </x-docs.section>

            {{-- Conventions --}}
            <x-docs.section id="conventions" title="Requests, errors aur limits" icon="info">
                <h3>Headers</h3>
                <table>
                    <thead><tr><th>Header</th><th>Value</th></tr></thead>
                    <tbody>
                        <tr><td><code>Authorization</code></td><td><code>Bearer &lt;token&gt;</code> (sirf <code>POST /auth/token</code> ke ilawa har jagah)</td></tr>
                        <tr><td><code>Accept</code></td><td><code>application/json</code> (zaroori, warna errors HTML mein aa sakte hain)</td></tr>
                        <tr><td><code>Content-Type</code></td><td><code>application/json</code>, ya file upload ke liye <code>multipart/form-data</code></td></tr>
                    </tbody>
                </table>
                <h3>Tareekh aur waqt</h3>
                <ul>
                    <li><strong>Jawab mein</strong> har waqt ISO-8601 UTC hota hai, jaise <code>2026-10-20T04:00:00+00:00</code>.</li>
                    <li><strong>Campaign bhejte waqt</strong> <code>start_at</code>, <code>end_at</code> aur items ka <code>scheduled_at</code> campaign ke <code>timezone</code> mein local waqt hain, jaise <code>"2026-10-20 09:00"</code> + <code>"timezone": "Asia/Karachi"</code>.</li>
                    <li>Logs ke <code>from</code> / <code>to</code> filter (<code>Y-m-d</code>) token wale user ke timezone mein hain.</li>
                </ul>
                <h3>Pagination</h3>
                <p>List endpoints <code>?page=2&amp;per_page=50</code> lete hain (default 20 ya 25, zyada se zyada 100):</p>
                <x-docs.code title="Paginated response">{
  "data": [ ... ],
  "links": { "first": "...?page=1", "last": "...?page=4", "prev": null, "next": "...?page=2" },
  "meta": { "current_page": 1, "from": 1, "last_page": 4, "per_page": 20, "to": 20, "total": 73, "path": "..." }
}</x-docs.code>
                <h3>Errors</h3>
                <table>
                    <thead><tr><th>Code</th><th>Matlab</th></tr></thead>
                    <tbody>
                        <tr><td><code>401</code></td><td>Token nahi, ghalat ya revoke: <code>{"message":"Unauthenticated."}</code></td></tr>
                        <tr><td><code>403</code></td><td>Is user ke paas permission nahi, ya account disabled.</td></tr>
                        <tr><td><code>404</code></td><td>Record nahi mila (ya delete ho chuka).</td></tr>
                        <tr><td><code>422</code></td><td>Validation ya kaam mumkin nahi; <code>message</code> aur field-wise <code>errors</code>.</td></tr>
                        <tr><td><code>429</code></td><td>Rate limit; <code>Retry-After</code> header jitne second ruk kar dobara.</td></tr>
                    </tbody>
                </table>
                <x-docs.code title="422 Unprocessable Content">{
  "message": "Media URLs must use HTTPS.",
  "errors": { "media_url": ["Media URLs must use HTTPS."] }
}</x-docs.code>
                <h3>Rate limits</h3>
                <table>
                    <thead><tr><th>Endpoints</th><th>Hadd</th></tr></thead>
                    <tbody>
                        <tr><td><code>POST /auth/token</code></td><td>10 requests / minute fi IP</td></tr>
                        <tr><td>Telegram ko chhoone wale (<code>/bots/{id}/test</code>, <code>/destinations/{id}/test</code>, <code>/campaigns/{id}/run</code>)</td><td>30 / minute fi user</td></tr>
                        <tr><td>Baqi sab</td><td>120 / minute fi user</td></tr>
                    </tbody>
                </table>
            </x-docs.section>

            {{-- Endpoint list --}}
            <x-docs.section id="endpoints" title="Endpoint list" icon="list">
                <table>
                    <thead><tr><th>Method</th><th>Path</th><th>Kaam</th></tr></thead>
                    <tbody>
                        @foreach ([
                            ['POST', '/auth/token', 'Token lena', 'auth-endpoints'],
                            ['DELETE', '/auth/token', 'Maujooda token band karna', 'auth-endpoints'],
                            ['GET', '/me', 'Current user aur permissions', 'auth-endpoints'],
                            ['GET', '/bots', 'Bots ki list', 'bots'],
                            ['POST', '/bots', 'Bot add', 'bots'],
                            ['GET', '/bots/{id}', 'Ek bot', 'bots'],
                            ['PUT', '/bots/{id}', 'Bot update', 'bots'],
                            ['DELETE', '/bots/{id}', 'Bot delete', 'bots'],
                            ['POST', '/bots/{id}/test', 'Token test (getMe)', 'bots'],
                            ['GET', '/destinations', 'Channels / groups ki list', 'destinations'],
                            ['POST', '/destinations', 'Destination add', 'destinations'],
                            ['GET', '/destinations/{id}', 'Ek destination', 'destinations'],
                            ['PUT', '/destinations/{id}', 'Destination update', 'destinations'],
                            ['DELETE', '/destinations/{id}', 'Destination delete', 'destinations'],
                            ['POST', '/destinations/{id}/test', 'Connection test (queue)', 'destinations'],
                            ['GET', '/contents', 'Content ki list', 'contents'],
                            ['POST', '/contents', 'Content banana (JSON ya file)', 'contents'],
                            ['GET', '/contents/{id}', 'Ek content', 'contents'],
                            ['PUT', '/contents/{id}', 'Content update (partial)', 'contents'],
                            ['DELETE', '/contents/{id}', 'Content delete', 'contents'],
                            ['GET', '/campaigns', 'Campaigns ki list', 'campaigns'],
                            ['POST', '/campaigns', 'Campaign banana (+ activate)', 'campaigns'],
                            ['GET', '/campaigns/{id}', 'Campaign, items aur progress', 'campaigns'],
                            ['PUT', '/campaigns/{id}', 'Campaign update', 'campaigns'],
                            ['DELETE', '/campaigns/{id}', 'Campaign delete', 'campaigns'],
                            ['POST', '/campaigns/{id}/activate · run · pause · resume · stop · restart', 'Lifecycle actions', 'campaign-actions'],
                            ['POST', '/campaigns/{id}/items/{item}/skip · retry', 'Item actions', 'campaign-actions'],
                            ['GET', '/campaigns/{id}/logs', 'Ek campaign ke logs', 'campaign-actions'],
                            ['GET', '/logs', 'Saare posting logs', 'logs'],
                        ] as [$m, $p, $d, $anchor])
                            <tr>
                                <td><span @class([
                                    'rounded px-1.5 py-0.5 font-mono text-[11px] font-bold',
                                    'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200' => $m === 'GET',
                                    'bg-brand-100 text-brand-800 dark:bg-brand-500/20 dark:text-brand-200' => $m === 'POST',
                                    'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200' => $m === 'PUT',
                                    'bg-red-100 text-red-800 dark:bg-red-500/20 dark:text-red-200' => $m === 'DELETE',
                                ])>{{ $m }}</span></td>
                                <td><a href="#{{ $anchor }}" class="font-mono text-xs">{{ $p }}</a></td>
                                <td>{{ $d }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-docs.section>

            {{-- Auth endpoints --}}
            <x-docs.section id="auth-endpoints" title="Auth" icon="user">
                <x-docs.endpoint method="POST" path="/auth/token">
                    <p>E-mail aur password se naya token. Body: <code>email</code>, <code>password</code>, <code>device_name</code> (token ka naam, jaise "Android app"). Ghalat credentials ya disabled account par <code>422</code>.</p>
                </x-docs.endpoint>
                <x-docs.endpoint method="DELETE" path="/auth/token">
                    <p>Jis token se request aayi, sirf woh band (mobile app ka logout). Jawab: <code>{"message":"Token revoked."}</code></p>
                </x-docs.endpoint>
                <x-docs.endpoint method="GET" path="/me">
                    <x-docs.code title="200 OK">{
  "id": 1, "name": "Admin", "email": "admin@example.com", "role": "admin",
  "permissions": ["dashboard.view", "bots.view", "bots.manage", "campaigns.view", "..."],
  "timezone": "Asia/Karachi"
}</x-docs.code>
                    <p>Super Admin ke liye <code>permissions</code> <code>["*"]</code> hota hai. App is list se buttons dikha / chhupa sakti hai.</p>
                </x-docs.endpoint>
            </x-docs.section>

            {{-- Bots --}}
            <x-docs.section id="bots" title="Bots" icon="bot">
                <x-docs.callout type="info">Bot ka token API ke kisi jawab mein kabhi nahi aata; sirf <code>masked_token</code> (jaise <code>123456789:••••••••••</code>).</x-docs.callout>
                <x-docs.endpoint method="GET" path="/bots" permission="bots.view">
                    <p>Query: <code>search</code> (naam / username), <code>status</code>, <code>per_page</code>.</p>
                    <x-docs.code title="200 OK — data[0]">{
  "id": 1, "name": "Marketing Bot", "username": "hunario_post_bot", "telegram_id": 7000001,
  "masked_token": "123456789:••••••••••", "status": "active", "connection_status": "connected",
  "last_error": null, "description": null, "last_checked_at": "2026-10-13T16:00:00+00:00",
  "last_activity_at": "2026-10-13T16:30:00+00:00", "destinations_count": 2,
  "created_at": "...", "updated_at": "..."
}</x-docs.code>
                </x-docs.endpoint>
                <x-docs.endpoint method="POST" path="/bots" permission="bots.manage">
                    <table>
                        <thead><tr><th>Field</th><th>Type</th><th>Zaroori</th><th>Note</th></tr></thead>
                        <tbody>
                            <tr><td><code>name</code></td><td>string ≤ 120</td><td>haan</td><td>Panel ka naam</td></tr>
                            <tr><td><code>token</code></td><td>string</td><td>haan</td><td>@BotFather ka token; wahi token dobara add nahi hota</td></tr>
                            <tr><td><code>status</code></td><td>string</td><td>haan</td><td><code>active</code> ya <code>disabled</code></td></tr>
                            <tr><td><code>description</code></td><td>string ≤ 2000</td><td>nahi</td><td></td></tr>
                        </tbody>
                    </table>
                    <p>Save hote hi token Telegram (<code>getMe</code>) se check hota hai. Jawab <code>201</code>, aur <code>connection_status</code> batata hai ke token sahi tha ya nahi.</p>
                </x-docs.endpoint>
                <x-docs.endpoint method="PUT" path="/bots/{id}" permission="bots.manage">
                    <p>Wahi fields; <code>name</code> aur <code>status</code> zaroori. <code>token</code> na bhejein to purana token hi rehta hai.</p>
                </x-docs.endpoint>
                <x-docs.endpoint method="DELETE" path="/bots/{id}" permission="bots.manage">
                    <p>Bot aur us ke destinations hata deta hai (posting history mehfooz). Agar koi active campaign is bot ko istemal kar rahi ho to <code>422</code>.</p>
                </x-docs.endpoint>
                <x-docs.endpoint method="POST" path="/bots/{id}/test" permission="bots.manage">
                    <p>Token dobara check. Kamyabi par <code>200</code>, warna <code>422</code>; dono mein <code>{"ok": bool, "message": "...", "bot": {...}}</code>.</p>
                </x-docs.endpoint>
            </x-docs.section>

            {{-- Destinations --}}
            <x-docs.section id="destinations" title="Destinations (channels & groups)" icon="megaphone">
                <x-docs.endpoint method="GET" path="/destinations" permission="destinations.view">
                    <p>Query: <code>bot_id</code>, <code>type</code>, <code>status</code>, <code>search</code> (naam ya chat ID), <code>per_page</code>.</p>
                    <x-docs.code title="200 OK — data[0]">{
  "id": 1, "bot_id": 1, "bot": { "id": 1, "name": "Marketing Bot" },
  "name": "My Channel", "chat_id": "-1001234567890", "username": "my_channel",
  "type": "channel", "status": "active", "connection_status": "connected",
  "telegram_title": "My Channel", "bot_is_admin": true, "bot_can_post": true,
  "is_test_chat": false, "last_error": null, "description": null,
  "last_checked_at": "...", "created_at": "..."
}</x-docs.code>
                </x-docs.endpoint>
                <x-docs.endpoint method="POST" path="/destinations" permission="destinations.manage">
                    <table>
                        <thead><tr><th>Field</th><th>Type</th><th>Zaroori</th><th>Note</th></tr></thead>
                        <tbody>
                            <tr><td><code>bot_id</code></td><td>integer</td><td>haan</td><td>Jis bot se post hoga</td></tr>
                            <tr><td><code>name</code></td><td>string ≤ 120</td><td>haan</td><td></td></tr>
                            <tr><td><code>chat_id</code></td><td>string</td><td>haan</td><td><code>-100…</code>, <code>@username</code> ya <code>t.me/username</code></td></tr>
                            <tr><td><code>type</code></td><td>string</td><td>haan</td><td><code>channel</code>, <code>group</code>, <code>supergroup</code></td></tr>
                            <tr><td><code>status</code></td><td>string</td><td>haan</td><td><code>active</code> ya <code>inactive</code></td></tr>
                            <tr><td><code>is_test_chat</code></td><td>boolean</td><td>nahi</td><td>Test mode ke liye</td></tr>
                            <tr><td><code>description</code></td><td>string</td><td>nahi</td><td></td></tr>
                        </tbody>
                    </table>
                </x-docs.endpoint>
                <x-docs.endpoint method="PUT" path="/destinations/{id}" permission="destinations.manage">
                    <p>POST wale saare zaroori fields dobara bhejein. <code>bot_id</code> ya <code>chat_id</code> badalne par connection status "unknown" ho jata hai; dobara test karein.</p>
                </x-docs.endpoint>
                <x-docs.endpoint method="DELETE" path="/destinations/{id}" permission="destinations.manage">
                    <p>Active campaign istemal kar rahi ho to <code>422</code>.</p>
                </x-docs.endpoint>
                <x-docs.endpoint method="POST" path="/destinations/{id}/test" permission="destinations.manage">
                    <p>Body (optional): <code>{"send_message": false}</code> sirf permissions check karta hai. Test queue mein chalta hai: jawab <code>202</code> aur <code>connection_status: "testing"</code>. Kuch second baad <code>GET /destinations/{id}</code> se nateeja dekhein: <code>connected</code> ya <code>failed</code> + <code>last_error</code>.</p>
                </x-docs.endpoint>
            </x-docs.section>

            {{-- Contents --}}
            <x-docs.section id="contents" title="Contents" icon="photo">
                <x-docs.endpoint method="GET" path="/contents" permission="contents.view">
                    <p>Query: <code>search</code> (title, text, caption, tag), <code>type</code>, <code>status</code>, <code>tag</code> (tag ka slug, jaise <code>promo</code>), <code>per_page</code>.</p>
                </x-docs.endpoint>
                <x-docs.endpoint method="POST" path="/contents" permission="contents.manage">
                    <table>
                        <thead><tr><th>Field</th><th>Type</th><th>Note</th></tr></thead>
                        <tbody>
                            <tr><td><code>title</code></td><td>string ≤ 200</td><td><strong>Zaroori.</strong> Panel ke liye naam</td></tr>
                            <tr><td><code>type</code></td><td>string</td><td><strong>Zaroori.</strong> <code>text</code>, <code>image</code>, <code>video</code>, <code>document</code>, <code>audio</code>, <code>animation</code></td></tr>
                            <tr><td><code>source</code></td><td>string</td><td>Media ke liye zaroori: <code>url</code>, <code>upload</code>, <code>file_id</code>. Text ke liye khud <code>none</code></td></tr>
                            <tr><td><code>text</code></td><td>string ≤ 4096</td><td>Text type ke liye zaroori</td></tr>
                            <tr><td><code>caption</code></td><td>string ≤ 1024</td><td>Media ke sath</td></tr>
                            <tr><td><code>parse_mode</code></td><td>string</td><td><code>HTML</code>, <code>MarkdownV2</code> ya na bhejein</td></tr>
                            <tr><td><code>media_url</code></td><td>https URL</td><td><code>source=url</code> par zaroori; public host hona chahiye</td></tr>
                            <tr><td><code>telegram_file_id</code></td><td>string</td><td><code>source=file_id</code> par zaroori</td></tr>
                            <tr><td><code>upload</code></td><td>file</td><td><code>source=upload</code> par zaroori (multipart). Image 10 MB, baqi 50 MB</td></tr>
                            <tr><td><code>tags</code></td><td>array of strings</td><td>Naye tags khud bante hain (≤ 20)</td></tr>
                            <tr><td><code>status</code></td><td>string</td><td>Default <code>active</code></td></tr>
                            <tr><td><code>disable_notification</code>, <code>disable_web_page_preview</code>, <code>protect_content</code></td><td>boolean</td><td>Optional</td></tr>
                            <tr><td><code>notes</code>, <code>metadata</code>, <code>thumbnail</code></td><td>string / object / https URL</td><td>Optional</td></tr>
                        </tbody>
                    </table>
                    <x-docs.code title="Text post">curl -X POST {{ $base }}/contents \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"title":"Welcome","type":"text","text":"&lt;b&gt;Hello&lt;/b&gt; channel!","parse_mode":"HTML","tags":["promo"]}'</x-docs.code>
                    <x-docs.code title="Video by URL">curl -X POST {{ $base }}/contents \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"title":"Video 1","type":"video","source":"url","media_url":"https://cdn.example.com/video1.mp4","caption":"New video"}'</x-docs.code>
                    <x-docs.code title="File upload (multipart)">curl -X POST {{ $base }}/contents \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -F title="Brochure" -F type=document -F source=upload -F upload=@brochure.pdf</x-docs.code>
                </x-docs.endpoint>
                <x-docs.endpoint method="PUT" path="/contents/{id}" permission="contents.manage">
                    <p>Partial update: sirf woh fields bhejein jo badalne hain. Media badalne par purani cached Telegram file_id khud hat jati hai.</p>
                    <x-docs.callout type="warning">PHP <code>PUT</code> ke sath multipart file nahi parhta. File badalni ho to <code>POST /contents/{id}</code> bhejein aur form mein <code>_method=PUT</code> shamil karein.</x-docs.callout>
                </x-docs.endpoint>
                <x-docs.endpoint method="DELETE" path="/contents/{id}" permission="contents.manage">
                    <p>Agar content kisi adhoori (draft / scheduled / running / paused) campaign mein hai to <code>422</code>.</p>
                </x-docs.endpoint>
            </x-docs.section>

            {{-- Campaigns --}}
            <x-docs.section id="campaigns" title="Campaigns" icon="bolt">
                <x-docs.endpoint method="GET" path="/campaigns" permission="campaigns.view">
                    <p>Query: <code>search</code>, <code>status</code>, <code>bot_id</code>, <code>destination_id</code>, <code>per_page</code>.</p>
                </x-docs.endpoint>
                <x-docs.endpoint method="POST" path="/campaigns" permission="campaigns.manage">
                    <table>
                        <thead><tr><th>Field</th><th>Type</th><th>Note</th></tr></thead>
                        <tbody>
                            <tr><td><code>name</code></td><td>string ≤ 150</td><td><strong>Zaroori</strong></td></tr>
                            <tr><td><code>description</code></td><td>string</td><td>Optional</td></tr>
                            <tr><td><code>bot_id</code>, <code>destination_id</code></td><td>integer</td><td><strong>Zaroori.</strong> Destination usi bot ki ho</td></tr>
                            <tr><td><code>test_mode</code>, <code>test_destination_id</code></td><td>boolean, integer</td><td>Test mode on ho to test chat zaroori (usi bot ki)</td></tr>
                            <tr><td><code>posting_order</code></td><td>string</td><td><strong>Zaroori.</strong> <code>sequential</code> ya <code>random</code></td></tr>
                            <tr><td><code>trigger_type</code></td><td>string</td><td><strong>Zaroori.</strong> <code>manual</code>, <code>scheduled</code>, <code>interval</code>, <code>fixed_time</code>, <code>daily</code>, <code>weekly</code></td></tr>
                            <tr><td><code>timezone</code></td><td>string</td><td><strong>Zaroori.</strong> Jaise <code>Asia/Karachi</code></td></tr>
                            <tr><td><code>start_at</code>, <code>end_at</code></td><td>"Y-m-d H:i"</td><td>Optional, <code>timezone</code> mein local waqt; end start ke baad</td></tr>
                            <tr><td><code>interval_seconds</code></td><td>integer</td><td><code>interval</code> ke liye zaroori, 60 se 2678400 (31 din)</td></tr>
                            <tr><td><code>slots</code></td><td>array</td><td><code>fixed_time</code> / <code>daily</code> / <code>weekly</code> ke liye: <code>[{"time":"09:00","days":[1,3,5]}]</code>. <code>days</code> sirf weekly mein (1 = Monday … 7 = Sunday)</td></tr>
                            <tr><td><code>items</code></td><td>array</td><td>Tarteeb se: <code>[{"content_id":5},{"content_id":7,"scheduled_at":"2026-10-20 18:30"}]</code>. <code>scheduled_at</code> sirf <code>scheduled</code> trigger mein (har item ke liye zaroori)</td></tr>
                            <tr><td><code>repeat_mode</code></td><td>string</td><td><strong>Zaroori.</strong> <code>none</code>, <code>immediate</code>, <code>daily</code>, <code>weekly</code>, <code>indefinite</code></td></tr>
                            <tr><td><code>repeat_limit</code></td><td>integer</td><td>Optional: immediate / daily / weekly mein kitni dafa</td></tr>
                            <tr><td><code>failure_behavior</code></td><td>string</td><td><strong>Zaroori.</strong> <code>retry_then_continue</code>, <code>stop_on_failure</code>, <code>continue</code>, <code>retry_until_success</code></td></tr>
                            <tr><td><code>max_retries</code></td><td>integer 0–20</td><td><strong>Zaroori</strong></td></tr>
                            <tr><td><code>retry_delay_seconds</code></td><td>integer 5–86400</td><td><strong>Zaroori.</strong> Har retry par double</td></tr>
                            <tr><td><code>activate</code></td><td>boolean</td><td>Sirf POST: <code>true</code> ho to bante hi activate (<code>campaigns.operate</code> permission bhi chahiye)</td></tr>
                        </tbody>
                    </table>
                    <x-docs.code title="Har 30 minute, 09:00 se, foran activate">curl -X POST {{ $base }}/campaigns \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{
    "name": "Daily Videos", "bot_id": 1, "destination_id": 1,
    "posting_order": "sequential", "trigger_type": "interval", "interval_seconds": 1800,
    "timezone": "Asia/Karachi", "start_at": "2026-10-20 09:00",
    "repeat_mode": "none", "failure_behavior": "retry_then_continue",
    "max_retries": 3, "retry_delay_seconds": 60,
    "items": [{"content_id": 1}, {"content_id": 2}, {"content_id": 3}],
    "activate": true
  }'</x-docs.code>
                    <x-docs.code title="Weekly: Mon, Wed, Fri 19:00">{ ..., "trigger_type": "weekly", "slots": [{ "time": "19:00", "days": [1, 3, 5] }], ... }</x-docs.code>
                </x-docs.endpoint>
                <x-docs.endpoint method="GET" path="/campaigns/{id}" permission="campaigns.view">
                    <x-docs.code title="200 OK — data (khulasa)">{
  "id": 3, "name": "Daily Videos", "status": "running", "trigger_type": "interval",
  "interval_seconds": 1800, "timezone": "Asia/Karachi", "cycle": 1,
  "next_run_at": "2026-10-20T04:30:00+00:00", "last_run_at": "2026-10-20T04:00:00+00:00",
  "progress": { "total": 3, "pending": 2, "processing": 0, "sent": 1, "failed": 0, "skipped": 0, "done": 1, "percent": 33 },
  "slots": [],
  "items": [
    { "id": 10, "content_id": 1, "content_title": "Video 1", "sort_order": 1, "status": "sent",
      "attempts": 1, "sent_at": "2026-10-20T04:00:03+00:00", "telegram_message_id": "481", "error_message": null },
    { "id": 11, "content_id": 2, "content_title": "Video 2", "sort_order": 2, "status": "pending", "...": "..." }
  ]
}</x-docs.code>
                </x-docs.endpoint>
                <x-docs.endpoint method="PUT" path="/campaigns/{id}" permission="campaigns.manage">
                    <p>POST wale saare zaroori fields dobara bhejein. <code>items</code> na bhejein to sequence waisa hi rehta hai; bhejein to woh poori nayi tarteeb hai (purane item rakhne ke liye un ka <code>id</code> bhejein). Fixed time / daily / weekly mein <code>slots</code> <strong>hamesha</strong> bhejein.</p>
                    <x-docs.callout type="warning">Running campaign edit nahi hoti (<code>422</code> "Pause the campaign before editing it."). Pehle <code>/pause</code>, phir PUT, phir <code>/resume</code>.</x-docs.callout>
                </x-docs.endpoint>
                <x-docs.endpoint method="DELETE" path="/campaigns/{id}" permission="campaigns.manage">
                    <p>Running ya scheduled campaign delete nahi hoti; pehle <code>/stop</code>. Logs mehfooz rehte hain.</p>
                </x-docs.endpoint>
            </x-docs.section>

            {{-- Campaign actions --}}
            <x-docs.section id="campaign-actions" title="Campaign actions" icon="play">
                <p>Sab <code>POST</code> hain, body khaali, permission <code>campaigns.operate</code>. Kamyabi par (Run ke ilawa) poori campaign wapas aati hai; jo kaam mumkin na ho us par <code>422</code> aur wajah.</p>
                <table>
                    <thead><tr><th>Path</th><th>Kaam</th><th>Kis status se</th></tr></thead>
                    <tbody>
                        <tr><td><code>/campaigns/{id}/activate</code></td><td>Shuru karna (completed / stopped / failed par nayi cycle)</td><td>draft, completed, cancelled, failed</td></tr>
                        <tr><td><code>/campaigns/{id}/run</code></td><td>Agla item foran; jawab <code>202</code> <code>{"message":"Run Now queued.","campaign":{...}}</code></td><td>scheduled, running, paused</td></tr>
                        <tr><td><code>/campaigns/{id}/pause</code></td><td>Nayi posts rokna</td><td>scheduled, running</td></tr>
                        <tr><td><code>/campaigns/{id}/resume</code></td><td>Agle pending item se jaari</td><td>paused, failed</td></tr>
                        <tr><td><code>/campaigns/{id}/stop</code></td><td>Band karna (status <code>cancelled</code>)</td><td>scheduled, running, paused, failed</td></tr>
                        <tr><td><code>/campaigns/{id}/restart</code></td><td>Item #1 se nayi cycle, logs mehfooz</td><td>draft ke ilawa</td></tr>
                        <tr><td><code>/campaigns/{id}/items/{item}/skip</code></td><td>Pending / failed item chhorna</td><td>—</td></tr>
                        <tr><td><code>/campaigns/{id}/items/{item}/retry</code></td><td>Failed / skipped item dobara pending</td><td>—</td></tr>
                    </tbody>
                </table>
                <x-docs.endpoint method="GET" path="/campaigns/{id}/logs" permission="logs.view">
                    <p>Is campaign ke posting logs, naye pehle. Query: <code>status</code>, <code>per_page</code>, <code>with_response=1</code>.</p>
                </x-docs.endpoint>
            </x-docs.section>

            {{-- Logs --}}
            <x-docs.section id="logs" title="Posting logs" icon="list">
                <x-docs.endpoint method="GET" path="/logs" permission="logs.view">
                    <p>Query: <code>campaign_id</code>, <code>bot_id</code>, <code>destination_id</code>, <code>status</code> (<code>pending</code>, <code>processing</code>, <code>sent</code>, <code>failed</code>, <code>skipped</code>), <code>from</code>, <code>to</code> (<code>Y-m-d</code>), <code>with_response=1</code> (Telegram ka poora jawab bhi), <code>per_page</code>.</p>
                    <x-docs.code title="200 OK — data[0]">{
  "id": 52, "campaign_id": 3, "campaign_item_id": 10, "bot_id": 1, "destination_id": 1,
  "content_id": 1, "content_title": "Video 1", "status": "sent", "trigger": "schedule",
  "is_test": false, "chat_id": "-1001234567890", "cycle": 1, "attempts": 1,
  "telegram_message_id": "481", "scheduled_at": "2026-10-20T04:00:00+00:00",
  "sent_at": "2026-10-20T04:00:03+00:00", "duration_ms": 812,
  "error_code": null, "error_message": null, "created_at": "..."
}</x-docs.code>
                    <p><code>trigger</code>: <code>schedule</code>, <code>manual</code> (Run now) ya <code>retry</code>. Har retry ka apna log row hota hai (<code>attempts</code> barhta hai).</p>
                </x-docs.endpoint>
            </x-docs.section>

            {{-- Enums --}}
            <x-docs.section id="enums" title="Allowed values" icon="tag">
                <table>
                    <thead><tr><th>Field</th><th>Values</th></tr></thead>
                    <tbody>
                        @foreach ($enums as $label => $values)
                            <tr>
                                <td class="whitespace-nowrap">{{ $label }}</td>
                                <td>@foreach ($values as $v)<code>{{ $v }}</code>@if (! $loop->last), @endif @endforeach</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-docs.section>

            {{-- Example flow --}}
            <x-docs.section id="example-flow" title="Complete example: zero se pehli post" icon="send">
                <p>Yeh script bot, channel, content aur campaign bana kar foran ek post bhejti hai, phir nateeja dikhati hai. <code>jq</code> JSON se IDs nikalta hai.</p>
                <x-docs.code title="bash">API={{ $base }}
H=(-H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json")

# 1) Bot
BOT=$(curl -s -X POST $API/bots "${H[@]}" -d '{"name":"Marketing Bot","token":"&lt;BOTFATHER_TOKEN&gt;","status":"active"}' | jq .data.id)

# 2) Channel (bot is channel ka admin ho) + test
DEST=$(curl -s -X POST $API/destinations "${H[@]}" -d "{\"bot_id\":$BOT,\"name\":\"My Channel\",\"chat_id\":\"@my_channel\",\"type\":\"channel\",\"status\":\"active\"}" | jq .data.id)
curl -s -X POST $API/destinations/$DEST/test "${H[@]}"

# 3) Content
C1=$(curl -s -X POST $API/contents "${H[@]}" -d '{"title":"Hello","type":"text","text":"Hello from the API!"}' | jq .data.id)

# 4) Manual campaign, activate
CAMP=$(curl -s -X POST $API/campaigns "${H[@]}" -d "{\"name\":\"API test\",\"bot_id\":$BOT,\"destination_id\":$DEST,\"posting_order\":\"sequential\",\"trigger_type\":\"manual\",\"timezone\":\"Asia/Karachi\",\"repeat_mode\":\"none\",\"failure_behavior\":\"retry_then_continue\",\"max_retries\":3,\"retry_delay_seconds\":60,\"items\":[{\"content_id\":$C1}],\"activate\":true}" | jq .data.id)

# 5) Run now, phir log dekhein
curl -s -X POST $API/campaigns/$CAMP/run "${H[@]}"
sleep 5
curl -s "$API/campaigns/$CAMP/logs" "${H[@]}" | jq '.data[0] | {status, telegram_message_id, error_message}'</x-docs.code>
            </x-docs.section>

        </div>
    </div>
</div>
