import requests, re, uuid, random, string, datetime
import json, sys 

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

def chat2(message: str, anon_id: str = None, max_retries: int = 3):
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


def parse_response(raw: str) -> str:
    """
    Parse hooshang.ai API weird response format into clean text.
    """
    lines = raw.splitlines()
    output = []

    for line in lines:
        line = line.strip()
        # lines like 0:"some text"
        m = re.match(r'0:"(.*)"', line)
        if m:
            text = m.group(1)
            # fix escaped unicode like â to proper utf-8
            try:
                text = bytes(text, "latin1").decode("utf-8")
            except:
                pass
            output.append(text)

    return "".join(output).strip()

def chat(message: str, anon_id: str = None, max_retries: int = 3):
    if not anon_id:
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
        try:
            return parse_response(txt)
        except Exception as e:
            return f"⚠️ Failed to parse: {e}\nRaw:\n{txt}"
    return f"Failed after {max_retries} tries."

def chat_stream2(message: str, anon_id=None):
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

def chat_stream(message: str, anon_id=None):
    if not anon_id:
        m = re.search(r"anonymous-user-id=([^;]+)", BASE_COOKIE)
        anon_id = m.group(1) if m else rand5()

    headers = dict(HEADERS)
    headers["Cookie"] = set_anon_cookie(BASE_COOKIE, anon_id)

    with requests.post(API_URL, headers=headers, json=build_payload(message), stream=True) as r:
        buffer = ""
        for chunk in r.iter_content(chunk_size=None, decode_unicode=True):
            if not chunk:
                continue

            if "You have reached the maximum number of messages" in chunk:
                print("\n[limit reached, new anon id needed]")
                break

            # process line by line
            buffer += chunk
            while "\n" in buffer:
                line, buffer = buffer.split("\n", 1)
                line = line.strip()

                # we only care about lines like 0:"..."
                m = re.match(r'0:"(.*)"', line)
                if m:
                    text = m.group(1)
                    # fix mojibake (weird â chars)
                    try:
                        text = bytes(text, "latin1").decode("utf-8")
                    except:
                        pass
                    sys.stdout.write(text)
                    sys.stdout.flush()

if __name__ == "__main__":
    # chat_stream("say a welcome in en")
    # print(chat("say a welcome in en"))
    pass
prompt = """

You are tasked with generating a JSON description of a house scene for AI image generation.

The JSON should follow this structure:
{
 "description": "... general description ...",
 "environment": {...}, // based on Q1
 "weather": {...},   // based on Q2
 "house": {...},    // based on Q3
 "lighting": {...},   // based on Q4
 "extra_spaces": [...], // based on Q5
 "interior": {...}   // detailed furniture, decor, kitchen, architecture
}

Each part corresponds to one of the following **questions**:

1. دوست دارید خانه‌تان در چه نوع محیطی قرار داشته باشد؟
 a) نزدیک دریا
 b) جنگل
 c) دامنه کوه
 d) باغ پر از گل

2. کدام آب‌وهوا برای شما دلپذیرتر است؟
 a) آفتابی و گرم
 b) بارانی و خنک
 c) معتدل بهاری
 d) برفی و سرد

3. چه سبک دکوراسیونی را برای خانه‌تان می‌پسندید؟
 a) مینیمال
 b) بوهو رنگی
 c) مدرن و هوشمند
 d) سنتی و گرم

4. چه نوع نورپردازی را برای فضای خانه ترجیح می‌دهید؟
 a) نور طبیعی
 b) نور دراماتیک
 c) نور زرد و گرم
 d) ترکیبی

5. کدام فضای اضافی را در خانه خود می‌پسندید؟
 a) حیاط بزرگ
 b) تراس وسیع
 c) اتاق مطالعه
 d) استودیوی هنری
 
### Important structural rule (applies to ALL outputs):
- The **camera always looks straight at the front wall**.  
- **Front wall** = full glass, divided into **3 panels**.  
- **Left and right walls** = solid walls meeting the glass wall at right angles.  
- This structural setup is constant and must always appear in the "house" or "architecture" part of the JSON output, regardless of the chosen answers.

---

### Randomly chosen answers (for this run):
1 → c) دامنه کوه
2 → not specify
3 →not specify 
4 →not specify 
5 → not specify 

---

### Example JSON output: be creative and make cool out put

{
 "description": "A cozy traditional house located on a mountain hillside, surrounded by pine trees and mist, with a warm and nostalgic interior.",
 "environment": {
  "type": "mountain",
  "description": "خانه‌ای در دامنه کوه، با چشم‌انداز قله‌ها و هوای تازه",
  "elements": ["pine trees", "rocky hills", "mountain peaks"]
 },
 "weather": {
  "type": "rainy",
  "description": "هوای خنک و بارانی، با آسمانی ابری و رطوبت در هوا",
  "elements": ["clouds", "light rain", "mist"]
 },
 "house": {
  "style": "traditional",
  "description": "خانه‌ای سنتی و گرم با مبلمان چوبی و جزئیات نوستالژیک",
  "elements": ["wooden furniture", "carpets", "stone fireplace"]
 },
 "lighting": {
  "type": "warm yellow",
  "description": "نور زرد و گرم که فضای دنج و صمیمی ایجاد می‌کند",
  "elements": ["lamps", "warm glow", "candle-like ambiance"]
 },
 "extra_spaces": [
  {
   "type": "terrace",
   "description": "یک تراس وسیع با منظره کوه و درختان اطراف",
   "elements": ["stone floor terrace", "wooden railing", "mountain view"]
  }
 ],
 "interior": {
  "furniture": {
   "sofa": {"type": "traditional wooden sofa", "color": "dark brown", "position": "living room"},
   "coffee_table": {"type": "rectangular", "material": "wood", "position": "center of room"},
   "rug": {"type": "Persian carpet", "color": "red and gold", "position": "under coffee table"},
   "dining_table": {"type": "wooden", "chairs": "classic chairs around", "position": "next to fireplace"}
  },
  "decor": {
   "lighting": {"lamps": "yellow warm lamps", "chandelier": "rustic iron chandelier"},
   "clock": {"type": "antique wall clock", "position": "above fireplace"}
  },
  "kitchen": {
   "design": "rustic traditional",
   "cabinets": "wooden cabinets with carved details",
   "appliances": ["stone oven"],
   "counter": "wooden countertop"
  },
  "architecture": {
   "flooring": "stone tiles",
   "walls": "natural stone and wood",
   "ceiling": "wooden beams",
   "windows": {"size": "large", "feature": "view of mountains"}
  }
 }
} 

### >>>> important froget json and give pure raw txt sutble for comfy ui no json
"""

#comfi ui 
prompt = """

You are tasked with generating a raw descriptive scene of a house for AI image generation.  

The description should always include:  
- A clear **environment** (Q1).  
- The **atmosphere / mood** of the space (Q2).  
- The **materials** used for the house (Q3).  
- An artistic or cultural touch (Q4).  
- The **lighting style** of the house (Q5).  

### Important structural rule (applies to ALL outputs):
- The **camera always looks straight at the front wall**.  
- **Front wall** = full glass, divided into **3 panels**.  
- **Left and right walls** = solid walls meeting the glass wall at right angles.  
- This structural setup must always be described in the scene.

---

Each part corresponds to one of the following **questions**:

1. کجا برات الهام بخش تره؟
 a) کوهستان
 b) دل جنگل
 c) وسط صحرا
 d) ساحل دریا

2. کدوم فضا آرامش بیشتری بهت میده؟
 a) رنگارنگ و پر جزییات
 b) سنتی و نوستالژیک
 c) مینیمال و خلوت
 d) مدرن و تکنولوژیک

3. کدوم متریال رو ترجیح میدی؟!
 a) سرامیک
 b) چوب و بتن
 c) پارچه و توری
 d) کاه گل

4. هنر مورد علاقه‌ات چیه؟!
 a) سینما
 b) سفالگری
 c) موسیقی
 d) ادبیات

5. نورپردازی داخلی خونه‌ت چطور باشه؟
 a) نور طبیعی زیاد
 b) چراغ‌های سقفی ساده
 c) آباژور و نور نقطه‌ای
 d) نور مخفی و مدرن

---

### Randomly chosen answers (for this run):
1 → a) کوهستان  
2 → b) سنتی و نوستالژیک  
3 → b) چوب و بتن  
4 → c) موسیقی  
5 → d) نور مخفی و مدرن  

---

### Example raw text output: be creative and immersive

A nostalgic mountain house built with wood and concrete, sitting among pine trees and rocky hills.  
The front wall is a full glass surface divided into three panels, while the left and right walls are solid and connect at right angles.  
Inside, the space feels traditional and warm, with stone flooring, wooden beams, and rustic textures.  
The atmosphere is inspired by music — a piano near the glass wall reflects soft light, shelves of vinyl records line the wall, and the space hums with creative energy.  
Lighting comes from hidden modern fixtures, spreading a soft glow across walls and ceiling, blending warmth with elegance.  

### >>>> important: only raw text, no JSON, no lists
"""



# loop thorow all possible combinations 

#comfi ui 
prompt = f"""

You are tasked with generating a raw descriptive scene of a house for AI image generation.  

The description should always include:  
- A clear **environment** (Q1).  
- The **atmosphere / mood** of the space (Q2).  
- The **materials** used for the house (Q3).  
- An artistic or cultural touch (Q4).  
- The **lighting style** of the house (Q5).  

### Important structural rule (applies to ALL outputs):
- The **camera always looks straight at the front wall**.  
- **Front wall** = full glass, divided into **3 panels**.  
- **Left and right walls** = solid walls meeting the glass wall at right angles.  
- This structural setup must always be described in the scene.

---

Each part corresponds to one of the following **questions**:

1. کجا برات الهام بخش تره؟
 a) کوهستان
 b) دل جنگل
 c) وسط صحرا
 d) ساحل دریا

2. کدوم فضا آرامش بیشتری بهت میده؟
 a) رنگارنگ و پر جزییات
 b) سنتی و نوستالژیک
 c) مینیمال و خلوت
 d) مدرن و تکنولوژیک

3. کدوم متریال رو ترجیح میدی؟!
 a) سرامیک
 b) چوب و بتن
 c) پارچه و توری
 d) کاه گل

4. هنر مورد علاقه‌ات چیه؟!
 a) سینما
 b) سفالگری
 c) موسیقی
 d) ادبیات

5. نورپردازی داخلی خونه‌ت چطور باشه؟
 a) نور طبیعی زیاد
 b) چراغ‌های سقفی ساده
 c) آباژور و نور نقطه‌ای
 d) نور مخفی و مدرن

---

### Randomly chosen answers (for this run):
1 → a) کوهستان  
2 → b) سنتی و نوستالژیک  
3 → b) چوب و بتن  
4 → c) موسیقی  
5 → d) نور مخفی و مدرن  

---

### Example raw text output: be creative and immersive

A nostalgic mountain house built with wood and concrete, sitting among pine trees and rocky hills.  
The front wall is a full glass surface divided into three panels, while the left and right walls are solid and connect at right angles.  
Inside, the space feels traditional and warm, with stone flooring, wooden beams, and rustic textures.  
The atmosphere is inspired by music — a piano near the glass wall reflects soft light, shelves of vinyl records line the wall, and the space hums with creative energy.  
Lighting comes from hidden modern fixtures, spreading a soft glow across walls and ceiling, blending warmth with elegance.  

### >>>> important: only raw text, no JSON, no lists
"""

ans = {
    1: ["کوهستان", "دل جنگل", "وسط صحرا", "ساحل دریا", "مشخص نیست"],
    2: ["رنگارنگ و پر جزییات", "سنتی و نوستالژیک", "مینیمال و خلوت", "مدرن و تکنولوژیک", "مشخص نیست"],
    3: ["سرامیک", "چوب و بتن", "پارچه و توری", "کاه گل", "مشخص نیست"],
    4: ["سینما", "سفالگری", "موسیقی", "ادبیات", "مشخص نیست"],
    5: ["نور طبیعی زیاد", "چراغ‌های سقفی ساده", "آباژور و نور نقطه‌ای", "نور مخفی و مدرن", "مشخص نیست"]
}

print()
print()
print()
print((chat(prompt)))
