document.addEventListener("DOMContentLoaded", function () {
const persianKeys = [
    { '0': 'چ', '1': 'ج', '2': 'ح', '3': 'خ', '4': 'ه', '5': 'ع', '6': 'غ', '7': 'ف', '8': 'ق', '9': 'ث', '10': 'ص', '11': 'ض' },
    { '0': 'گ', '1': 'ک', '2': 'م', '3': 'ن', '4': 'ت', '5': 'ا', '6': 'ل', '7': 'ب', '8': 'ی', '9': 'س', '10': 'ش' },
    { '0': '.', '1': '،', '2': 'و', '3': 'پ', '4': 'د', '5': 'ذ', '6': 'ر', '7': 'ز', '8': 'ط', '9': 'ظ' }
];
const numpadKeys = [
  { '0': '3', '1': '2', '2': '1' },
  { '0': '6', '1': '5', '2': '4' },
  { '0': '9', '1': '8', '2': '7' },
  {
    '0': '0'
  }
];
KioskBoard.init({
  keysArrayOfObjects: persianKeys,
  keysNumpadArrayOfNumbers: null,  // 🔴 اینجا numpad غیرفعاله
});

KioskBoard.run('#phoneNumber', {
  language: 'fa',
  theme: 'flat',
  allowRealKeyboard: true,
  keysArrayOfObjects: numpadKeys, 
  keysNumpadArrayOfNumbers: null   // 🔴 دوباره null تا numpad نیاد
});

KioskBoard.run('#fullName', {
  language: 'fa',
  theme: 'flat',
  allowRealKeyboard: true,
  keysArrayOfObjects: persianKeys, 
  keysNumpadArrayOfNumbers: null   // 🔴 اینجا هم null
});

const phoneInput = document.getElementById('phoneNumber');
const NameInput = document.getElementById('fullName');

function hideKioskboardParts() {
  setTimeout(() => {
    const topRow = document.querySelector('.kioskboard-row-top');
    if (topRow) {
      topRow.style.setProperty("display", "none", "important");
    }

    const spaceKey = document.querySelector('#KioskBoard-VirtualKeyboard .kioskboard-row-bottom span.kioskboard-key-space');
    if (spaceKey) {
      spaceKey.style.setProperty("display", "none", "important");
    }
  }, 100);
}
function hideKioskboardnum() {
  setTimeout(() => {
    const topRow = document.querySelector('.kioskboard-row-top');
    if (topRow) {
      topRow.style.setProperty("display", "none", "important");
    }
  }, 10);
}

phoneInput.addEventListener('focus', hideKioskboardParts);
phoneInput.addEventListener('click', hideKioskboardParts);

NameInput.addEventListener('focus', hideKioskboardnum);
NameInput.addEventListener('click', hideKioskboardnum);



const API_URL = "http://127.0.0.1:3000/backend/data.php";   //  API rout
// const API_URL = "http://192.168.43.123:3000/backend/data.php";   //  API rout
// const API_URL = "http://192.168.93.88:3000/backend/data.php";   //  API rout
const ADMIN_PASS = "admin123";    // admin pass
let userId = null;
let currentQuestion = 0;

const questions = [
    {
        q: "دوست داری خونه‌ت تو چه جور طبیعتی قرار داشته باشه؟",
        answers: ["کنار دریا", "وسط جنگل", "نزدیک کوه", "در شهر"]
    },
    {
        q: "چه آب و هوایی برات لذتبخش‌تره؟",
        answers: ["آفتابی و گرم", "خنک و بارونی", "سرد و برفی", "ملایم و معتدل"]
    },
    {
        q: "بیشتر به چه فضاهایی علاقه‌مندی؟",
        answers: ["مدرن و تکنولوژیک", "سنتی و نوستالژیک", "مینیمال و ساده", "لوکس و شیک"]
    },
    {
        q: "کدام نوع رنگ در دکوراسیون خانه را ترجیح می‌دهی؟",
        answers: ["رنگ‌های روشن", "رنگ‌های تیره", "رنگ‌های طبیعی و خاکی", "رنگ‌های شاد و متنوع"]
    },
    {
        q: "چه نوع نورپردازی‌ای برای خانه دوست داری؟",
        answers: ["نور طبیعی فراوان", "چراغ‌های ملایم و گرم", "چراغ‌های مدرن و LED", "ترکیبی از همه"]
    }
];

const MAX_QUESTIONS = questions.length;
async function continueGame() {
    // Fetch the current game state from the API
    const data = await apiGet("get_update");

    if (data.status === "ok") {
        const answersCount = data.answers.length;

        // If the game is ending or all questions are answered, show the ending page
        if (data.state === "ending" || answersCount >= MAX_QUESTIONS) {
            endGame();
        }
        // If the game is in the middle of being played, resume from the last question
        else if (data.state === "playing" && answersCount > 0) {
            const lastAnswer = data.answers[answersCount - 1];
            currentQuestion = parseInt(lastAnswer.question_id, 10);
            userId = lastAnswer.user_id; // Set user ID to continue with the same session
            showQuestion(currentQuestion);
            console.log('resume game after reload');


        } else if (data.state === "playing" && answersCount == 0) {
            startGame()
            console.log('start game after reload');

        }

    } else {
        // If API call fails, just start a new game
        console.log('api fail');

    }
}

function randomUserId() {
    return "user_" + Math.random().toString(36).substring(2, 10);
}

async function apiGet(action) {
    const url = API_URL + "?action=" + action;
    const res = await fetch(url);
    return res.json();
}

async function apiPost(action, body, admin = false) {
    const url = API_URL + "?action=" + action + (admin ? "&admin_password=" + ADMIN_PASS : "");
    const res = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body || {})
    });
    return res.json();
}

function showQuestion(index) {
    if (index >= questions.length) {
        endGame();
        return;
    }
    const q = questions[index];
    document.getElementById("title").innerText = "سوال " + (index + 1);
    const content = document.getElementById("content");
    content.innerHTML = `<p style="font-size:22px;">${q.q}</p>`;
    const buttons = [];
    q.answers.forEach((ans, i) => {
        const btn = document.createElement("button");
        btn.className = "btn";
        btn.innerText = ans;
        btn.onclick = () => {
            // Disable all buttons when one is clicked
            buttons.forEach(b => {
                b.disabled = true;
                b.style.opacity = "0.6";
                b.style.cursor = "not-allowed";
            });
            submitAnswer(index + 1, i + 1);
        };
        buttons.push(btn);
        content.appendChild(btn);
    });
}

async function submitAnswer(questionId, answer) {
    await apiPost("set_answer", { user_id: userId, qa: questionId + "-" + answer });
    currentQuestion++;
    showQuestion(currentQuestion);
}

async function startGame() {
    userId = randomUserId();
    currentQuestion = 0;
    await apiPost("set_state", { state: "playing" }, true);
    showQuestion(currentQuestion);
}

async function resetGame (prams) {
    await apiPost("end_game", {}, true);
    location.reload();
}
window.resetGame = resetGame;
async function endGame() {
    document.getElementById("title").innerText = "بازی تمام شد 🎉";
    const content = document.getElementById("content");
    // content.innerHTML = `<button class="btn" onclick="startGame()">شروع دوباره</button>`;
    await apiPost("set_state", { state: "ending" }, true);
    content.innerHTML = `<button class="btn" onclick="resetGame()">شروع دوباره</button>`;
}


document.getElementById("startBtn").addEventListener("click", startGame);
window.onload = continueGame;



});