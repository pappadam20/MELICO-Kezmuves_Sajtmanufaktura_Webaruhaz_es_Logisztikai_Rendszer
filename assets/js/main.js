/*=============== SHOW MENU ===============*/
const navMenu = document.getElementById('nav-menu'),
      navToggle = document.getElementById('nav-toggle'),
      navClose = document.getElementById('nav-close')

/* Menu show */
if(navToggle){
    navToggle.addEventListener('click', () =>{
        navMenu.classList.add('show-menu')
    })
}

/* Menu hidden */
if(navClose){
    navClose.addEventListener('click', () =>{
        navMenu.classList.remove('show-menu')
    })
}


/*=============== REMOVE MENU MOBILE ===============*/
const navLink = document.querySelectorAll('.nav__link')

const linkAction = () =>{
    const navMenu = document.getElementById('nav-menu')
    // When we click on each nav__link, we remove the show-menu class
    navMenu.classList.remove('show-menu')
}
navLink.forEach(n => n.addEventListener('click', linkAction))


/*=============== ADD BLUR HEADER ===============*/
const blurHeader = () =>{
    const header = document.getElementById('header')
    // Add a class if the bottom offset is greater than 50 viewport height, add the blur-header class to the header
    this.scrollY >= 50 ? header.classList.add('blur-header') 
                       : header.classList.remove('blur-header')
}
window.addEventListener('scroll', blurHeader)


/*=============== SHOW SCROLL UP ===============*/ 
const scrollUp = () =>{
    const scrollUp = document.getElementById('scroll-up')
    // When the scroll is higher than 350 viewport height, add the
    this.scrollY >= 350 ? scrollUp.classList.add('show-scroll') 
                       : scrollUp.classList.remove('show-scroll')
}
window.addEventListener('scroll', scrollUp)


/*=============== SCROLL SECTIONS ACTIVE LINK ===============*/
const sections = document.querySelectorAll('section[id]')
    
const scrollActive = () =>{
  	const scrollDown = window.scrollY

	sections.forEach(current =>{
		const sectionHeight = current.offsetHeight,
			  sectionTop = current.offsetTop - 58,
			  sectionId = current.getAttribute('id'),
			  sectionsClass = document.querySelector('.nav__menu a[href*=' + sectionId + ']')

		if(scrollDown > sectionTop && scrollDown <= sectionTop + sectionHeight){
			sectionsClass.classList.add('active-link')
		}else{
			sectionsClass.classList.remove('active-link')
		}                                                    
	})
}
window.addEventListener('scroll', scrollActive)




/*=============== SCROLL REVEAL ANIMATION ===============*/
const sr = ScrollReveal({
    origin: 'top',
    distance: '40px',
    opacity: 1,
    scale: 1.1,
    duration: 2500,
    delay: 300,
    //reset: true, // Animations repeat
})



const signInBtn = document.getElementById("signInBtn");

signInBtn.addEventListener("click", () => {
    window.location.href = "signIn.html";
});




/*=============== ÚJ: FELHASZNÁLÓI ÁLLAPOT KEZELÉSE ===============*/
function updateUI() {
    const isLoggedIn = localStorage.getItem('isLoggedIn');
    const loginItem = document.getElementById('nav-login-item');
    const userItem = document.getElementById('nav-user-item');
    const userEmailDisplay = document.getElementById('user-email-display');
    const loginTimeInfo = document.getElementById('login-time-info');

    if (isLoggedIn === 'true') {
        if(loginItem) loginItem.style.display = 'none';
        if(userItem) userItem.style.display = 'block';
        
        if(userEmailDisplay) userEmailDisplay.innerText = localStorage.getItem('userEmail');
        if(loginTimeInfo) loginTimeInfo.innerText = "Belépve: " + localStorage.getItem('lastLogin');
    } else {
        // Ha nincs belépve, biztosítsuk a Login gomb megjelenését
        if(loginItem) loginItem.style.display = 'block';
        if(userItem) userItem.style.display = 'none';
    }
}
