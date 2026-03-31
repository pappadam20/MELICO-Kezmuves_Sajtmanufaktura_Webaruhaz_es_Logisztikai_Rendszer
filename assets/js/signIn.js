const container = document.getElementById('container');
const registerBtn = document.getElementById('register');
const loginBtn = document.getElementById('login');

registerBtn.addEventListener('click', () => {
    container.classList.add("active");
});

loginBtn.addEventListener('click', () => {
    container.classList.remove("active");
});

document.getElementById("backBtn").addEventListener("click", () => {
    window.location.href = "index.html";
});


// Megkeressük a bejelentkezési formot
const signInForm = document.getElementById('signInForm');

if (signInForm) {
    signInForm.addEventListener('submit', function (e) {
        e.preventDefault();

        const emailValue = document.getElementById('loginEmail').value;
        const emailLower = emailValue.toLowerCase();
        
        // ADATOK MENTÉSE: Ezt látja majd a többi oldal
        localStorage.setItem('isLoggedIn', 'true');
        localStorage.setItem('userEmail', emailValue);
        localStorage.setItem('lastLogin', new Date().toLocaleString()); // Bejelentkezés ideje

        // Átirányítások
        if (emailLower.includes('admin')) {
            window.location.href = 'admin/kezdolapA.html'; 
        } 
        else if (emailLower.includes('futar') || emailLower.includes('futár')) {
            window.location.href = 'futar/futarModul.html';
        } 
        else {
            window.location.href = 'index.html';
        }
    });
}
