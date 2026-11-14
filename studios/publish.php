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

function morse_slug($value) {
  $slug = strtolower(trim($value));
  if (!preg_match("/^[a-z0-9][a-z0-9-]{1,22}[a-z0-9]$/", $slug)) return "";
  return $slug;
}

function morse_file($slug) {
  return rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . "morse-" . $slug . ".json";
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
  $slug = morse_slug(isset($_GET["site"]) ? $_GET["site"] : "");
  $file = $slug ? morse_file($slug) : "";
  if ($file === "" || !is_file($file)) {
    http_response_code(404);
    echo json_encode(["error" => "missing"]);
    exit;
  }
  readfile($file);
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
$slug = morse_slug(isset($data["slug"]) ? $data["slug"] : "");
if ($slug === "") {
  http_response_code(400);
  echo json_encode(["error" => "bad name"]);
  exit;
}
$site = $data["site"] ?? null;
if (!is_array($site) || !isset($site["pages"]) || !is_array($site["pages"])) {
  http_response_code(400);
  echo json_encode(["error" => "bad site"]);
  exit;
}
$payload = json_encode(
  ["title" => isset($site["title"]) ? $site["title"] : $slug, "pages" => $site["pages"]],
  JSON_UNESCAPED_SLASHES
);
if ($payload === false) {
  http_response_code(400);
  echo json_encode(["error" => "encode"]);
  exit;
}
if (@file_put_contents(morse_file($slug), $payload) === false) {
  http_response_code(500);
  echo json_encode(["error" => "save"]);
  exit;
}
echo json_encode(["ok" => true, "slug" => $slug]);
