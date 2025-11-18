import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';
import ScrollSmoother from 'gsap/ScrollSmoother';

gsap.registerPlugin(ScrollTrigger, ScrollSmoother);

// Animación scroll smoother
let smoother = ScrollSmoother.create({
  // smooth: 0.8,
  // speed: 0,
  // effects: true
  wrapper: "#smooth-wrapper",
  content: "#smooth-content",
  smooth: 2,
  effects: true,
});

gsap.utils.toArray("header .botonSmooth").forEach(function (button, i) {
  button.addEventListener("click", (e) => {
    var id = e.target.getAttribute("href");
    // console.log(id);
    smoother.scrollTo(id, true, "top top");
    e.preventDefault();
  });
});

// to view navigate to -  https://cdpn.io/pen/debug/XWVvMGr#section3
window.onload = (event) => {
  // console.log("page is fully loaded");

  let urlHash = window.location.href.split("#")[1];
  let scrollElem = urlHash && document.querySelector("#" + urlHash);

  // console.log(scrollElem, urlHash);

  if (scrollElem) {
    gsap.to(smoother, {
      scrollTop: smoother.offset(scrollElem, "top top"),
      duration: 1,
      delay: 0.5,
    });
  }
};
