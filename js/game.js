<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Infinite Vault: Quadrillionaire Clicker</title>
    <style>
        :root {
            --bg-color: #0f172a;
            --panel-bg: #1e293b;
            --accent-color: #38bdf8;
            --accent-hover: #0ea5e9;
            --text-color: #f8fafc;
            --text-muted: #94a3b8;
            --border-color: #334155;
            --success-color: #22c55e;
            --warning-color: #f59e0b;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        header {
            background-color: var(--panel-bg);
            border-bottom: 2px solid var(--border-color);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        h1 {
            font-size: 1.5rem;
            color: var(--accent-color);
            text-shadow: 0 0 10px rgba(56, 189, 248, 0.3);
        }

        .stats-summary {
            display: flex;
            gap: 2rem;
            font-size: 1.1rem;
        }

        .stats-summary span {
            font-weight: bold;
            color: var(--success-color);
        }

        .main-container {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

        /* Sidebar / Navigation */
        sidebar {
            width: 220px;
            background-color: var(--panel-bg);
            border-right: 2px solid var(--border-color);
            display: flex;
            flex-direction: column;
        }

        .nav-btn {
            background: none;
            border: none;
            color: var(--text-muted);
            padding: 1rem 1.5rem;
            text-align: left;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.2s;
            border-left: 4px solid transparent;
        }

        .nav-btn:hover {
            background-color: rgba(56, 189, 248, 0.05);
            color: var(--text-color);
        }

        .nav-btn.active {
            background-color: rgba(56, 189, 248, 0.1);
            color: var(--accent-color);
            border-left-color: var(--accent-color);
            font-weight: bold;
        }

        /* Content Area */
        .content-area {
            flex: 1;
            padding: 2rem;
            overflow-y: auto;
            position: relative;
        }

        .tab-content {
            display: none;
            animation: fadeIn 0.3s ease-in-out;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Clicker Tab */
        .clicker-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            height: 100%;
        }

        .click-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: rgba(30, 41, 59, 0.5);
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 2rem;
        }

        #big-clicker {
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: radial-gradient(circle, var(--accent-color) 0%, var(--accent-hover) 100%);
            border: none;
            box-shadow: 0 0 30px rgba(56, 189, 248, 0.4);
            cursor: pointer;
            font-size: 2rem;
            color: #0f172a;
            font-weight: bold;
            transition: transform 0.1s, box-shadow 0.1s;
            display: flex;
            align-items: center;
            justify-content: center;
            user-select: none;
        }

        #big-clicker:active {
            transform: scale(0.92);
            box-shadow: 0 0 10px rgba(56, 189, 248, 0.8);
        }

        .click-stats {
            margin-top: 1.5rem;
            text-align: center;
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        /* Upgrades & Buildings */
        .shop-section {
            background: rgba(30, 41, 59, 0.5);
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            overflow-y: auto;
            max-height: 70vh;
        }

        .shop-section h2 {
            margin-bottom: 1rem;
            font-size: 1.2rem;
            color: var(--accent-color);
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.5rem;
        }

        .item-card {
            background: var(--panel-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: border-color 0.2s;
        }

        .item-card:hover {
            border-color: var(--accent-color);
        }

        .item-info h3 {
            font-size: 1rem;
            margin-bottom: 0.2rem;
        }

        .item-info p {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .item-action {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 0.5rem;
        }

        button.buy-btn {
            background-color: var(--accent-color);
            color: #0f172a;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        button.buy-btn:hover:not(:disabled) {
            background-color: var(--accent-hover);
        }

        button.buy-btn:disabled {
            background-color: var(--border-color);
            color: var(--text-muted);
            cursor: not-allowed;
        }

        /* Story Tab */
        .story-container {
            max-width: 700px;
            margin: 0 auto;
            background: rgba(30, 41, 59, 0.5);
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 2rem;
        }

        .story-log {
            margin-top: 1rem;
            line-height: 1.6;
            font-size: 1rem;
            color: var(--text-muted);
        }

        .story-log p {
            margin-bottom: 1rem;
            padding: 0.75rem;
            background: var(--panel-bg);
            border-left: 4px solid var(--accent-color);
            border-radius: 0 6px 6px 0;
            color: var(--text-color);
        }

        /* Achievements Tab */
        .achievements-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 1rem;
        }

        .achievement-card {
            background: var(--panel-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            opacity: 0.4;
            transition: opacity 0.3s, border-color 0.3s;
        }

        .achievement-card.unlocked {
            opacity: 1;
            border-color: var(--success-color);
            background: rgba(34, 197, 94, 0.05);
        }

        .achievement-card h4 {
            font-size: 0.95rem;
            margin-bottom: 0.2rem;
        }

        .achievement-card p {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        /* Floating Click Text Animation */
        .floating-text {
            position: absolute;
            font-weight: bold;
            color: var(--success-color);
            pointer-events: none;
            animation: floatUp 0.8s ease-out forwards;
            font-size: 1.2rem;
            z-index: 100;
        }

        @keyframes floatUp {
            0% { opacity: 1; transform: translateY(0) scale(1); }
            100% { opacity: 0; transform: translateY(-60px) scale(1.2); }
        }
    </style>
</head>
<body>

    <header>
        <h1>The Infinite Vault</h1>
        <div class="stats-summary">
            <div>Money: <span id="money-display">$0</span></div>
            <div>Per Second: <span id="cps-display">$0/s</span></div>
        </div>
    </header>

    <div class="main-container">
        <!-- Sidebar Tabs -->
        <sidebar>
            <button class="nav-btn active" onclick="switchTab('clicker')">⚡ Vault Console</button>
            <button class="nav-btn" onclick="switchTab('story')">📖 Chronicles</button>
            <button class="nav-btn" onclick="switchTab('achievements')">🏆 Milestones</button>
        </sidebar>

        <!-- Main Content Panel -->
        <div class="content-area">
            
            <!-- Clicker Tab -->
            <div id="tab-clicker" class="tab-content active">
                <div class="clicker-layout">
                    <div class="click-section" id="clicker-container">
                        <button id="big-clicker">PRINT $</button>
                        <div class="click-stats">
                            <div>Power per click: <span id="click-power-display">1</span></div>
                            <div>Total clicks: <span id="total-clicks-display">0</span></div>
                        </div>
                    </div>

                    <div class="shop-section">
                        <h2>Automated Monopolies</h2>
                        <div id="buildings-list">
                            <!-- Populated via JavaScript -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- Story Tab -->
            <div id="tab-story" class="tab-content">
                <div class="story-container">
                    <h2>Chronicles of the Infinite Economy</h2>
                    <div class="story-log" id="story-log">
                        <p><strong>Log #1:</strong> You plug an old generator into an illegal quantum ledger terminal in your basement. Every time you press the button, reality blips, and currency floods into your account.</p>
                    </div>
                </div>
            </div>

            <!-- Achievements Tab -->
            <div id="tab-achievements" class="tab-content">
                <h2>Milestones & Trophies</h2>
                <div class="achievements-grid" id="achievements-list">
                    <!-- Populated via JavaScript -->
                </div>
            </div>

        </div>
    </div>

    <script>
        // Game State
        let game = {
            money: 0,
            totalEarned: 0,
            totalClicks: 0,
            clickPower: 1,
            cps: 0,
            buildings: {
                intern: { count: 0, baseCost: 15, baseCps: 0.5, name: "Unpaid Intern", desc: "Clicks buttons manually out of desperation." },
                printer: { count: 0, baseCost: 100, baseCps: 4, name: "Federal Printer", desc: "Brrrr goes the currency machine." },
                ai: { count: 0, baseCost: 1100, baseCps: 32, name: "Quant AI Agent", desc: "Trades stocks at superhuman speeds." },
                bank: { count: 0, baseCost: 12000, baseCps: 260, name: "Offshore Shell Corp", desc: "A maze of untraceable digital accounts." },
                planet: { count: 0, baseCost: 130000, baseCps: 1400, name: "Terraforming Syndicate", desc: "Monopolizes entire resource markets on Mars." },
                galaxy: { count: 0, baseCost: 1400000, baseCps: 7800, name: "Intergalactic Mint", desc: "Prints legal tender across stellar systems." },
                reality: { count: 0, baseCost: 20000000, baseCps: 44000, name: "Reality Fabricator", desc: "Rewrites physical law to manufacture wealth." }
            },
            achievements: {
                c1: { name: "First Dollar", desc: "Earn your very first currency unit.", unlocked: false, check: () => game.totalEarned >= 1 },
                c2: { name: "Thousandaire", desc: "Accumulate $1,000 total earned.", unlocked: false, check: () => game.totalEarned >= 1000 },
                c3: { name: "Millionaire", desc: "Accumulate $1,000,000 total earned.", unlocked: false, check: () => game.totalEarned >= 1000000 },
                c4: { name: "Billionaire", desc: "Accumulate $1,000,000,000 total earned.", unlocked: false, check: () => game.totalEarned >= 1e9 },
                c5: { name: "Trillionaire", desc: "Accumulate $1,000,000,000,000 total earned.", unlocked: false, check: () => game.totalEarned >= 1e12 },
                c6: { name: "Quadrillionaire", desc: "Reach the ultimate milestone of $1,000,000,000,000,000+!", unlocked: false, check: () => game.totalEarned >= 1e15 }
            },
            storyUnlocks: {
                1: false,
                2: false,
                3: false,
                4: false,
                5: false,
                6: false
            }
        };

        // Sound System (Web Audio API for noisy clicking and feedback)
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        function playSound(type) {
            if (!audioCtx) return;
            if (audioCtx.state === 'suspended') {
                audioCtx.resume();
            }
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.connect(gain);
            gain.connect(audioCtx.destination);

            if (type === 'click') {
                osc.type = 'triangle';
                osc.frequency.setValueAtTime(300, audioCtx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(600, audioCtx.currentTime + 0.08);
                gain.gain.setValueAtTime(0.15, audioCtx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.08);
                osc.start();
                osc.stop(audioCtx.currentTime + 0.08);
            } else if (type === 'buy') {
                osc.type = 'sine';
                osc.frequency.setValueAtTime(500, audioCtx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(1000, audioCtx.currentTime + 0.15);
                gain.gain.setValueAtTime(0.2, audioCtx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.15);
                osc.start();
                osc.stop(audioCtx.currentTime + 0.15);
            } else if (type === 'achievement') {
                osc.type = 'square';
                osc.frequency.setValueAtTime(400, audioCtx.currentTime);
                osc.frequency.setValueAtTime(600, audioCtx.currentTime + 0.1);
                osc.frequency.setValueAtTime(800, audioCtx.currentTime + 0.2);
                gain.gain.setValueAtTime(0.15, audioCtx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.3);
                osc.start();
                osc.stop(audioCtx.currentTime + 0.3);
            }
        }

        // Format Currency Function supporting insane numbers up to nonillions and beyond
        function formatMoney(val) {
            if (val < 1000) return '$' + val.toFixed(0);
            const suffixes = ["", "K", "M", "B", "T", "Qa", "Qi", "Sx", "Sp", "Oc", "No", "Dc"];
            const i = Math.floor(Math.log10(val) / 3);
            if (i >= suffixes.length) return '$' + val.toExponential(2);
            const shortVal = val / Math.pow(10, i * 3);
            return '$' + shortVal.toFixed(2) + ' ' + suffixes[i];
        }

        // Tab Navigation
        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.nav-btn').forEach(el => el.classList.remove('active'));
            document.getElementById('tab-' + tabId).classList.add('active');
            event.currentTarget.classList.add('active');
        }

        // Initialize Shop UI
        function initShop() {
            const container = document.getElementById('buildings-list');
            container.innerHTML = '';
            for (let key in game.buildings) {
                let b = game.buildings[key];
                let card = document.createElement('div');
                card.className = 'item-card';
                card.innerHTML = `
                    <div class="item-info">
                        <h3>${b.name} (<span id="b-count-${key}">${b.count}</span>)</h3>
                        <p>${b.desc}</p>
                        <p style="color: var(--accent-color); font-size: 0.75rem; margin-top: 2px;">Generates: +${formatMoney(b.baseCps)}/s</p>
                    </div>
                    <div class="item-action">
                        <span id="b-cost-${key}" style="font-size: 0.9rem; font-weight: bold;">${formatMoney(b.baseCost)}</span>
                        <button class="buy-btn" id="b-btn-${key}" onclick="buyBuilding('${key}')">Acquire</button>
                    </div>
                `;
                container.appendChild(card);
            }
        }

        // Initialize Achievements UI
        function initAchievements() {
            const container = document.getElementById('achievements-list');
            container.innerHTML = '';
            for (let key in game.achievements) {
                let ach = game.achievements[key];
                let card = document.createElement('div');
                card.className = `achievement-card ${ach.unlocked ? 'unlocked' : ''}`;
                card.id = `ach-${key}`;
                card.innerHTML = `
                    <h4>🏆 ${ach.name}</h4>
                    <p>${ach.desc}</p>
                `;
                container.appendChild(card);
            }
        }

        // Click Action
        document.getElementById('big-clicker').addEventListener('click', (e) => {
            game.money += game.clickPower;
            game.totalEarned += game.clickPower;
            game.totalClicks++;
            playSound('click');

            // Floating text feedback
            createFloatingText(e.clientX, e.clientY, '+' + formatMoney(game.clickPower));
            updateUI();
        });

        function createFloatingText(x, y, text) {
            let el = document.createElement('div');
            el.className = 'floating-text';
            el.innerText = text;
            el.style.left = x + 'px';
            el.style.top = y + 'px';
            document.body.appendChild(el);
            setTimeout(() => el.remove(), 800);
        }

        // Buy Building Function
        function buyBuilding(key) {
            let b = game.buildings[key];
            let cost = Math.floor(b.baseCost * Math.pow(1.15, b.count));
            if (game.money >= cost) {
                game.money -= cost;
                b.count++;
                playSound('buy');
                recalculateCPS();
                updateUI();
                initShop(); // refresh counts & costs
            }
        }

        function recalculateCPS() {
            let total = 0;
            for (let key in game.buildings) {
                total += game.buildings[key].count * game.buildings[key].baseCps;
            }
            game.cps = total;
        }

        // Story progression triggers
        function checkStoryProgression() {
            const storyLog = document.getElementById('story-log');

            if (game.totalEarned >= 1000 && !game.storyUnlocks[1]) {
                game.storyUnlocks[1] = true;
                addStoryEntry("<strong>Log #2:</strong> Word has gotten out about your basement code. Local syndicates and rogue interns are showing up with printers, eager to hook into your network.");
            }
            if (game.totalEarned >= 1000000 && !game.storyUnlocks[2]) {
                game.storyUnlocks[2] = true;
                addStoryEntry("<strong>Log #3:</strong> You've made your first million. Wall Street is shaking. Quantum AI algorithms are aggressively trading fictional commodities in the background, multiplying your liquidity exponentially.");
            }
            if (game.totalEarned >= 1e9 && !game.storyUnlocks[3]) {
                game.storyUnlocks[3] = true;
                addStoryEntry("<strong>Log #4:</strong> Billionaire status achieved. Offshore shell corporations now manage cash flows that outpace entire sovereign nations. The currency printer never stops.");
            }
            if (game.totalEarned >= 1e12 && !game.storyUnlocks[4]) {
                game.storyUnlocks[4] = true;
                addStoryEntry("<strong>Log #5:</strong> Trillionaire scale. Terraforming syndicates are buying entire planetary real estate markets and listing them as liquid securities.");
            }
            if (game.totalEarned >= 1e15 && !game.storyUnlocks[5]) {
                game.storyUnlocks[5] = true;
                addStoryEntry("<strong>Log #6:</strong> QUADRILLIONAIRE STATUS. You are warping macroeconomic reality itself. Intergalactic mints and reality fabricators pour infinite wealth into your accounts. You own the universe.");
            }
        }

        function addStoryEntry(htmlText) {
            const storyLog = document.getElementById('story-log');
            let p = document.createElement('p');
            p.innerHTML = htmlText;
            storyLog.prepend(p);
        }

        // Check Achievements
        function checkAchievements() {
            for (let key in game.achievements) {
                let ach = game.achievements[key];
                if (!ach.unlocked && ach.check()) {
                    ach.unlocked = true;
                    playSound('achievement');
                    let card = document.getElementById(`ach-${key}`);
                    if (card) card.classList.add('unlocked');
                    addStoryEntry(`<strong>Milestone Unlocked!</strong> Trophy earned: <em>${ach.name}</em> (${ach.desc})`);
                }
            }
        }

        // UI Refresh Loop
        function updateUI() {
            document.getElementById('money-display').innerText = formatMoney(game.money);
            document.getElementById('cps-display').innerText = formatMoney(game.cps) + '/s';
            document.getElementById('click-power-display').innerText = formatMoney(game.clickPower);
            document.getElementById('total-clicks-display').innerText = game.totalClicks.toLocaleString();

            // Update shop button states and dynamic costs
            for (let key in game.buildings) {
                let b = game.buildings[key];
                let cost = Math.floor(b.baseCost * Math.pow(1.15, b.count));
                
                let costEl = document.getElementById(`b-cost-${key}`);
                let btnEl = document.getElementById(`b-btn-${key}`);
                let countEl = document.getElementById(`b-count-${key}`);

                if (costEl) costEl.innerText = formatMoney(cost);
                if (countEl) countEl.innerText = b.count;
                if (btnEl) {
                    btnEl.disabled = game.money < cost;
                }
            }
        }

        // Game Loop (tick every 100ms)
        let lastTime = Date.now();
        function gameLoop() {
            let now = Date.now();
            let delta = (now - lastTime) / 1000;
            lastTime = now;

            if (game.cps > 0) {
                let earned = game.cps * delta;
                game.money += earned;
                game.totalEarned += earned;
            }

            checkAchievements();
            checkStoryProgression();
            updateUI();
        }

        // Initialization
        initShop();
        initAchievements();
        setInterval(gameLoop, 100);
    </script>
</body>
</html>