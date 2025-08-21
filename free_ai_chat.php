<?php
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

$input = $_GET['input'] ?? 'hi';
$BASE_COOKIE  = '__Host-authjs.csrf-token=bd406b8aab14dea0c0588568c0b8d3ea1dde1b68b5a9f7ab5abbcaf4442c6944|870623fbb47e59f06d02a8469b87280496fef88b9ecb407ceb37689d84eaf320; __Secure-authjs.callback-url=https://hooshang.ai; anonymous-user-id=37a6b658-f5c4-4c5e-b0b1-0a'.rand5().'62ebf; analytics_token=9df0eaa3-ce85-9747-b37b-759e23e08a90; analytics_session_token=ffd1f8d3-7dd9-b199-20e7-6626b55187b2; yektanet_session_last_activity=8/21/2025; _yngt_iframe=1; _ga=GA1.1.1723582230.1755762452; _ga_MGXBJZKJ3D=GS2.1.s1755762452$o1$g0$t1755762452$j60$l0$h0; _yngt=01K0C69ASZNZ4AK8VNBS5871TH; ph_phc_8LeaKzE0w9kcqzTSdSioztuyAr6V8OR6HjLc0QqPYeP_posthog={"distinct_id":"0198cb98-e328-7be0-98af-61dff3f62087","$sesid":[1755762456022,"0198cb98-e326-7496-80c5-50ccced08dab",1755762451238],"$epp":true,"$initial_person_info":{"r":"$direct","u":"https://hooshang.ai/"}}';

$HEADERS = [
    "accept: */*",
    "content-type: application/json",
    "origin: https://hooshang.ai",
    "referer: https://hooshang.ai",
    "user-agent: Mozilla/5.0"
];

function rand5() {
    $chars = 'abc0123456789';
    $str = '';
    for ($i = 0; $i < 5; $i++) $str .= $chars[random_int(0, strlen($chars)-1)];
    return $str;
}

function getAnonIdFromCookie($cookie) {
    if (preg_match('/anonymous-user-id=([^;]+)/', $cookie, $m)) return $m[1];
    return rand5();
}

function setAnonCookie($cookie, $anonId) {
    return preg_replace('/(anonymous-user-id=)[^;]+/', '${1}' . $anonId, $cookie);
}

function buildPayload($message) {
    return json_encode([
        "id" => uniqid(),
        "messages" => [[
            "id" => uniqid(),
            "createdAt" => gmdate("Y-m-d\TH:i:s.v\Z"),
            "role" => "user",
            "content" => $message,
            "parts" => [["type"=>"text","text"=>$message]]
        ]],
        "isGhostMode" => false
    ]);
}

// --- retry logic ---
$maxRetries = 5;
$anonId = getAnonIdFromCookie($BASE_COOKIE);

for ($i = 0; $i < $maxRetries; $i++) {

    $cookie = setAnonCookie($BASE_COOKIE, $anonId);
    $payload = buildPayload($input);

    $ch = curl_init("https://hooshang.ai/api/chat");
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($HEADERS, ["Cookie: $cookie"]));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $resp = curl_exec($ch);
    curl_close($ch);

    echo $resp;
    ob_flush(); flush();

    if (strpos($resp, "You have reached the maximum number of messages") !== false) {
        $anonId = rand5(); // new anon id
        echo "\n[Limit reached, retrying with new anon id: $anonId]\n";
        continue;
    }

    break; // success, exit loop
}
