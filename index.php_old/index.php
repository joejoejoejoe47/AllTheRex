<?php
// Set your Google Gemini API key here
define('GEMINI_API_KEY', 'YOUR_GEMINI_API_KEY_HERE');

// Process AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $inputData = json_decode(file_get_contents('php://input'), true);
    
    $prompt = $inputData['prompt'] ?? '';
    $engine = $inputData['engine'] ?? 'omni';
    $interactionId = $inputData['interaction_id'] ?? null;

    if (empty($prompt)) {
        echo json_encode(['error' => 'Prompt is required.']);
        exit;
    }

    if ($engine === 'veo') {
        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/veo-3.1:generateContent?key=' . GEMINI_API_KEY;
        $payload = [
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ]
        ];
    } else {
        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/interactions?key=' . GEMINI_API_KEY;
        $payload = [
            'model' => 'gemini-omni-flash-preview',
            'input' => $prompt
        ];
        
        if ($interactionId) {
            $payload['previous_interaction_id'] = $interactionId;
        }
    }

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        echo json_encode(['error' => 'API call failed.', 'details' => json_decode($response)]);
        exit;
    }

    $responseData = json_decode($response, true);
    $base64Video = '';
    $newInteractionId = null;

    if ($engine === 'veo') {
        $base64Video = $responseData['candidates'][0]['content']['parts'][0]['inlineData']['data'] ?? '';
    } else {
        $base64Video = $responseData['output_video']['data'] ?? '';
        $newInteractionId = $responseData['id'] ?? null;
    }

    echo json_encode([
        'success' => true,
        'video_data' => 'data:video/mp4;base64,' . $base64Video,
        'interaction_id' => $newInteractionId
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Canvas AI Video Studio</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0e0e11; color: #ececf1; }
        .app-layout { display: flex; height: 100vh; overflow: hidden; }
        
        /* Control Panel Sidebar */
        .sidebar { width: 340px; background: #17171e; border-right: 1px solid #2a2a36; padding: 20px; display: flex; flex-direction: column; gap: 15px; }
        h1 { font-size: 1.1rem; margin: 0 0 10px 0; color: #6366f1; text-transform: uppercase; letter-spacing: 0.05em; }
        
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        label { font-size: 0.85rem; color: #a1a1aa; font-weight: 500; }
        
        textarea { width: 100%; height: 90px; padding: 10px; border-radius: 6px; border: 1px solid #2a2a36; background: #0e0e11; color: #fff; resize: none; font-size: 0.9rem; }
        textarea:focus { border-color: #6366f1; outline: none; }
        
        select { padding: 8px 10px; border-radius: 6px; border: 1px solid #2a2a36; background: #0e0e11; color: #fff; font-size: 0.85rem; }
        
        .btn-primary { padding: 10px; background: #6366f1; border: none; border-radius: 6px; color: white; font-weight: 600; cursor: pointer; transition: background 0.2s; }
        .btn-primary:hover { background: #4f46e5; }
        .btn-primary:disabled { background: #3730a3; opacity: 0.6; cursor: not-allowed; }

        /* Canvas Workspace Area */
        .main-workspace { flex: 1; display: flex; flex-direction: column; justify-content: center; align-items: center; background: #08080a; position: relative; padding: 20px; }
        
        .canvas-container { position: relative; box-shadow: 0 20px 40px rgba(0,0,0,0.6); border-radius: 8px; overflow: hidden; border: 1px solid #2a2a36; }
        canvas { display: block; background: #000; cursor: pointer; }
        
        /* Canvas Floating Controls */
        .canvas-controls { position: absolute; bottom: 12px; left: 50%; transform: translateX(-50%); background: rgba(14, 14, 17, 0.85); backdrop-filter: blur(8px); padding: 8px 16px; border-radius: 20px; display: flex; gap: 12px; align-items: center; border: 1px solid rgba(255,255,255,0.1); }
        .btn-icon { background: transparent; border: none; color: #fff; cursor: pointer; font-size: 0.9rem; }
        
        .status-badge { font-size: 0.8rem; padding: 6px 10px; border-radius: 4px; background: #1e1e2d; color: #818cf8; min-height: 20px; text-align: center; }
        
        /* Hidden Native Video Element for Canvas Rendering */
        #sourceVideo { display: none; }
    </style>
</head>
<body>

<div class="app-layout">
    <!-- Control Sidebar -->
    <div class="sidebar">
        <h1>Canvas Studio AI</h1>
        
        <div class="form-group">
            <label for="engine">Model Pipeline</label>
            <select id="engine">
                <option value="omni">Gemini Omni Flash (Default & Edit)</option>
                <option value="veo">Veo 3.1 (Extend & Frame Lock)</option>
            </select>
        </div>

        <div class="form-group">
            <label for="prompt">Prompt / Edit Instruction</label>
            <textarea id="prompt" placeholder="e.g. A cybernetic owl perching on a glowing neon sign in a futuristic Tokyo alley..."></textarea>
        </div>

        <button id="renderBtn" class="btn-primary" onclick="requestGeneration()">Render to Canvas</button>

        <div id="status" class="status-badge">Ready for prompt</div>
    </div>

    <!-- Canvas Playback Workspace -->
    <div class="main-workspace">
        <div class="canvas-container">
            <canvas id="videoCanvas" width="854" height="480"></canvas>
            
            <div class="canvas-controls">
                <button class="btn-icon" onclick="togglePlayPause()" id="playBtn">▶ Play</button>
                <span id="timeDisplay" style="font-size: 0.8rem; font-family: monospace;">00:00 / 00:00</span>
            </div>
        </div>
    </div>
</div>

<video id="sourceVideo" crossorigin="anonymous" playsinline loop></video>

<script>
const canvas = document.getElementById('videoCanvas');
const ctx = canvas.getContext('2d');
const video = document.getElementById('sourceVideo');

let isPlaying = false;
let currentInteractionId = null;

// Draw Initial Placeholder on Canvas
function drawCanvasPlaceholder(text = "Enter a prompt to render video onto canvas") {
    ctx.fillStyle = "#0e0e11";
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    
    ctx.fillStyle = "#52525b";
    ctx.font = "14px -apple-system, BlinkMacSystemFont, sans-serif";
    ctx.textAlign = "center";
    ctx.fillText(text, canvas.width / 2, canvas.height / 2);
}

drawCanvasPlaceholder();

// Continuous Canvas Render Loop
function renderCanvasFrame() {
    if (!video.paused && !video.ended) {
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        updateTimeline();
        requestAnimationFrame(renderCanvasFrame);
    }
}

// Play / Pause Logic
function togglePlayPause() {
    if (!video.src) return;
    if (video.paused) {
        video.play();
        document.getElementById('playBtn').innerText = '⏸ Pause';
        requestAnimationFrame(renderCanvasFrame);
    } else {
        video.pause();
        document.getElementById('playBtn').innerText = '▶ Play';
    }
}

function updateTimeline() {
    const cur = formatTime(video.currentTime);
    const dur = formatTime(video.duration || 0);
    document.getElementById('timeDisplay').innerText = `${cur} / ${dur}`;
}

function formatTime(sec) {
    const m = Math.floor(sec / 60).toString().padStart(2, '0');
    const s = Math.floor(sec % 60).toString().padStart(2, '0');
    return `${m}:${s}`;
}

// API Generation Request
async function requestGeneration() {
    const prompt = document.getElementById('prompt').value.trim();
    const engine = document.getElementById('engine').value;
    const statusDiv = document.getElementById('status');
    const renderBtn = document.getElementById('renderBtn');

    if (!prompt) {
        alert('Please enter a prompt.');
        return;
    }

    renderBtn.disabled = true;
    statusDiv.innerText = "Rendering frame data...";
    drawCanvasPlaceholder("Rendering scene to video canvas...");

    try {
        const response = await fetch('index.php?action=generate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                prompt: prompt,
                engine: engine,
                interaction_id: currentInteractionId
            })
        });

        const result = await response.json();

        if (result.success && result.video_data) {
            statusDiv.innerText = "Render Complete";
            video.src = result.video_data;
            
            if (result.interaction_id) {
                currentInteractionId = result.interaction_id;
            }

            video.onloadedmetadata = () => {
                video.play();
                document.getElementById('playBtn').innerText = '⏸ Pause';
                requestAnimationFrame(renderCanvasFrame);
            };
        } else {
            statusDiv.innerText = "Error: " + (result.error || "Generation failed.");
            drawCanvasPlaceholder("Generation failed. Check console or API key.");
        }
    } catch (err) {
        statusDiv.innerText = "Connection Error";
        drawCanvasPlaceholder("Server Error.");
        console.error(err);
    } finally {
        renderBtn.disabled = false;
    }
}
</script>
</body>
</html>