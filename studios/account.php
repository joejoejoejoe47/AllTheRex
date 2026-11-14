<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Cache-Control: no-store");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  http_response_code(204);
  exit;
}

function morse_dir() {
  $dir = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . "morse-accounts";
  if (!is_dir($dir)) @mkdir($dir, 0777, true);
  return $dir;
}

function morse_read($path) {
  if (!is_file($path)) return null;
  $data = json_decode(file_get_contents($path), true);
  return is_array($data) ? $data : null;
}

function morse_write($path, $data) {
  return @file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES)) !== false;
}

function morse_index() {
  $path = morse_dir() . DIRECTORY_SEPARATOR . "index.json";
  $index = morse_read($path);
  return is_array($index) ? $index : [];
}

function morse_bag($id) {
  return morse_dir() . DIRECTORY_SEPARATOR . "bag-" . $id . ".json";
}

function morse_id($value) {
  $id = strtolower(trim((string) $value));
  if (!preg_match("/^[a-f0-9]{16}$/", $id)) return "";
  return $id;
}

function morse_known($id) {
  foreach (morse_index() as $account) {
    if (isset($account["id"]) && $account["id"] === $id) return $account;
  }
  return null;
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
  $id = morse_id(isset($_GET["id"]) ? $_GET["id"] : "");
  if ($id !== "") {
    if (!morse_known($id)) {
      http_response_code(404);
      echo json_encode(["error" => "missing"]);
      exit;
    }
    $bag = morse_read(morse_bag($id));
    $projects = $bag && isset($bag["projects"]) && is_array($bag["projects"]) ? $bag["projects"] : [];
    echo json_encode(["projects" => $projects]);
    exit;
  }
  $accounts = [];
  foreach (morse_index() as $account) {
    if (!isset($account["id"], $account["name"])) continue;
    $bag = morse_read(morse_bag($account["id"]));
    $names = [];
    if ($bag && isset($bag["projects"]) && is_array($bag["projects"])) {
      foreach ($bag["projects"] as $project) {
        if (!is_array($project) || !isset($project["id"])) continue;
        $names[] = [
          "id" => $project["id"],
          "name" => isset($project["name"]) ? $project["name"] : "Project",
        ];
      }
    }
    $accounts[] = ["id" => $account["id"], "name" => $account["name"], "projects" => $names];
  }
  echo json_encode(["accounts" => $accounts]);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["error" => "post"]);
  exit;
}

$raw = file_get_contents("php://input");
if ($raw === false || strlen($raw) > 12000000) {
  http_response_code(413);
  echo json_encode(["error" => "too big"]);
  exit;
}
$data = json_decode($raw, true);
if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(["error" => "bad json"]);
  exit;
}
$action = isset($data["action"]) ? $data["action"] : "";

if ($action === "create") {
  $name = trim(isset($data["name"]) ? $data["name"] : "");
  $name = preg_replace("/[\r\n\t]+/", " ", $name);
  if ($name === "" || strlen($name) > 32) {
    http_response_code(400);
    echo json_encode(["error" => "Name the account."]);
    exit;
  }
  $index = morse_index();
  $id = bin2hex(random_bytes(8));
  $index[] = ["id" => $id, "name" => $name];
  if (!morse_write(morse_dir() . DIRECTORY_SEPARATOR . "index.json", $index) || !morse_write(morse_bag($id), ["projects" => []])) {
    http_response_code(500);
    echo json_encode(["error" => "save"]);
    exit;
  }
  echo json_encode(["id" => $id, "name" => $name]);
  exit;
}

$id = morse_id(isset($data["id"]) ? $data["id"] : "");
if ($id === "" || !morse_known($id)) {
  http_response_code(404);
  echo json_encode(["error" => "missing"]);
  exit;
}
$bag = morse_read(morse_bag($id));
$projects = $bag && isset($bag["projects"]) && is_array($bag["projects"]) ? $bag["projects"] : [];

if ($action === "delete") {
  $projectId = isset($data["projectId"]) ? $data["projectId"] : "";
  $next = [];
  foreach ($projects as $project) {
    if (!is_array($project) || (isset($project["id"]) && $project["id"] === $projectId)) continue;
    $next[] = $project;
  }
  if (!morse_write(morse_bag($id), ["projects" => $next])) {
    http_response_code(500);
    echo json_encode(["error" => "save"]);
    exit;
  }
  echo json_encode(["ok" => true]);
  exit;
}

if ($action !== "save" || !isset($data["project"]) || !is_array($data["project"]) || !isset($data["project"]["id"])) {
  http_response_code(400);
  echo json_encode(["error" => "bad project"]);
  exit;
}
$incoming = $data["project"];
$next = [];
$replaced = false;
foreach ($projects as $project) {
  if (is_array($project) && isset($project["id"]) && $project["id"] === $incoming["id"]) {
    $next[] = $incoming;
    $replaced = true;
  } else {
    $next[] = $project;
  }
}
if (!$replaced) $next[] = $incoming;
if (count($next) > 40) {
  http_response_code(413);
  echo json_encode(["error" => "too many"]);
  exit;
}
if (!morse_write(morse_bag($id), ["projects" => $next])) {
  http_response_code(500);
  echo json_encode(["error" => "save"]);
  exit;
}
echo json_encode(["ok" => true]);
