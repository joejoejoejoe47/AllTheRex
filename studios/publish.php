<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  http_response_code(204);
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
$slug = isset($data["slug"]) ? strtolower($data["slug"]) : "";
if (!preg_match("/^[a-z0-9][a-z0-9-]{1,22}[a-z0-9]$/", $slug)) {
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
$dir = __DIR__ . "/sites";
if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
  http_response_code(500);
  echo json_encode(["error" => "no folder"]);
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
$written = @file_put_contents($dir . "/" . $slug . ".json", $payload);
if ($written === false) {
  http_response_code(500);
  echo json_encode(["error" => "save", "writable" => is_writable($dir)]);
  exit;
}
echo json_encode(["ok" => true, "slug" => $slug]);
