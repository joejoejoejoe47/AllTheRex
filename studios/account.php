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

function morse_library_path() {
  return morse_dir() . DIRECTORY_SEPARATOR . "library.json";
}

function morse_remember($project) {
  if (!is_array($project) || !isset($project["id"])) return;
  $lib = morse_read(morse_library_path());
  $projects = $lib && isset($lib["projects"]) && is_array($lib["projects"]) ? $lib["projects"] : [];
  $next = [];
  $replaced = false;
  foreach ($projects as $item) {
    if (is_array($item) && isset($item["id"]) && $item["id"] === $project["id"]) {
      $next[] = $project;
      $replaced = true;
    } else {
      $next[] = $item;
    }
  }
  if (!$replaced) $next[] = $project;
  morse_write(morse_library_path(), ["projects" => $next]);
}

function morse_all_projects() {
  $byId = [];
  $lib = morse_read(morse_library_path());
  if ($lib && isset($lib["projects"]) && is_array($lib["projects"])) {
    foreach ($lib["projects"] as $project) {
      if (is_array($project) && isset($project["id"])) $byId[$project["id"]] = $project;
    }
  }
  foreach (morse_index() as $account) {
    if (!isset($account["id"])) continue;
    $bag = morse_read(morse_bag($account["id"]));
    if (!$bag || !isset($bag["projects"]) || !is_array($bag["projects"])) continue;
    foreach ($bag["projects"] as $project) {
      if (is_array($project) && isset($project["id"])) $byId[$project["id"]] = $project;
    }
  }
  return array_values($byId);
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
  if (isset($_GET["catalog"])) {
    $names = [];
    foreach (morse_all_projects() as $project) {
      $names[] = [
        "id" => $project["id"],
        "name" => isset($project["name"]) && $project["name"] !== "" ? $project["name"] : "Project",
      ];
    }
    echo json_encode(["projects" => $names]);
    exit;
  }
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
  $wanted = [];
  if (isset($data["projectIds"]) && is_array($data["projectIds"])) {
    foreach ($data["projectIds"] as $projectId) {
      if (is_string($projectId) && $projectId !== "") $wanted[$projectId] = true;
    }
  }
  $chosen = [];
  foreach (morse_all_projects() as $project) {
    if (isset($wanted[$project["id"]])) $chosen[] = $project;
  }
  if (!morse_write(morse_dir() . DIRECTORY_SEPARATOR . "index.json", $index) || !morse_write(morse_bag($id), ["projects" => $chosen])) {
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
morse_remember($incoming);
echo json_encode(["ok" => true]);
