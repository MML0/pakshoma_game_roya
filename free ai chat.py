import requests, re, uuid, random, string, datetime

API_URL = "https://hooshang.ai/api/chat"

# Paste your cookie string exactly as seen in the curl (quotes not required)
BASE_COOKIE = """__Host-authjs.csrf-token=bd406b8aab14dea0c0588568c0b8d3ea1dde1b68b5a9f7ab5abbcaf4442c6944|870623fbb47e59f06d02a8469b87280496fef88b9ecb407ceb37689d84eaf320; __Secure-authjs.callback-url=https://hooshang.ai; anonymous-user-id=37a6b658-f5c4-4c5e-b0b1-5ac5bb062ebf; analytics_token=9df0eaa3-ce85-9747-b37b-759e23e08a90; analytics_session_token=ffd1f8d3-7dd9-b199-20e7-6626b55187b2; yektanet_session_last_activity=8/21/2025; _yngt_iframe=1; _ga=GA1.1.1723582230.1755762452; _ga_MGXBJZKJ3D=GS2.1.s1755762452$o1$g0$t1755762452$j60$l0$h0; _yngt=01K0C69ASZNZ4AK8VNBS5871TH; ph_phc_8LeaKzE0w9kcqzTSdSioztuyAr6V8OR6HjLc0QqPYeP_posthog={"distinct_id":"0198cb98-e328-7be0-98af-61dff3f62087","$sesid":[1755762456022,"0198cb98-e326-7496-80c5-50ccced08dab",1755762451238],"$epp":true,"$initial_person_info":{"r":"$direct","u":"https://hooshang.ai/"}}"""

HEADERS = {
    "accept": "*/*",
    "accept-language": "en-US,en;q=0.9,fa;q=0.8,lt;q=0.7",
    "content-type": "application/json",
    "origin": "https://hooshang.ai",
    "priority": "u=1, i",
    "referer": "https://hooshang.ai/",
    "sec-ch-ua": "\"Not;A=Brand\";v=\"99\", \"Google Chrome\";v=\"139\", \"Chromium\";v=\"139\"",
    "sec-ch-ua-mobile": "?0",
    "sec-ch-ua-platform": "\"Windows\"",
    "sec-fetch-dest": "empty",
    "sec-fetch-mode": "cors",
    "sec-fetch-site": "same-origin",
    "user-agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36",
}

def rand5():  # a–z0–9, length 5
    import string, random
    return ''.join(random.choices(string.ascii_lowercase + string.digits, k=5))

def set_anon_cookie(base_cookie: str, anon_id: str) -> str:
    return re.sub(r"(anonymous-user-id=)[^;]+", r"\1" + anon_id, base_cookie)

def iso_now():
    return datetime.datetime.utcnow().isoformat(timespec="milliseconds") + "Z"

def build_payload(message: str):
    return {
        "id": str(uuid.uuid4()),
        "messages": [{
            "id": str(uuid.uuid4()),
            "createdAt": iso_now(),
            "role": "user",
            "content": message,
            "parts": [{"type": "text", "text": message}]
        }],
        "isGhostMode": False
    }

def chat(message: str, anon_id: str = None, max_retries: int = 3):
    if not anon_id:
        # use the one inside BASE_COOKIE if present; otherwise random 5
        m = re.search(r"anonymous-user-id=([^;]+)", BASE_COOKIE)
        anon_id = m.group(1) if m else rand5()

    for _ in range(max_retries):
        headers = dict(HEADERS)
        headers["Cookie"] = set_anon_cookie(BASE_COOKIE, anon_id)
        r = requests.post(API_URL, headers=headers, json=build_payload(message), timeout=30)
        txt = r.text or ""
        if "You have reached the maximum number of messages" in txt:
            anon_id = rand5()
            print('max !!!')
            continue
        return txt
    return f"Failed after {max_retries} tries."


def chat_stream(message: str, anon_id=None):
    if not anon_id:
        m = re.search(r"anonymous-user-id=([^;]+)", BASE_COOKIE)
        anon_id = m.group(1) if m else rand5()

    headers = dict(HEADERS)
    headers["Cookie"] = set_anon_cookie(BASE_COOKIE, anon_id)

    with requests.post(API_URL, headers=headers, json=build_payload(message), stream=True) as r:
        for chunk in r.iter_content(chunk_size=None, decode_unicode=True):
            if chunk:
                if "You have reached the maximum number of messages" in chunk:
                    print("\n[limit reached, new anon id needed]")
                    break
                print(chunk, end="", flush=True)

if __name__ == "__main__":
    chat_stream("say a welcome in en")
    print(chat("say a welcome in en"))
