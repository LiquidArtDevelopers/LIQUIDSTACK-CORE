
document.addEventListener("click", createCursorRippleEffect);

function createCursorRippleEffect(e) {
   const ripple = document.createElement("div");
  
   ripple.className = "ripple";
   document.body.appendChild(ripple);

   ripple.style.left = `${e.clientX}px`;
   ripple.style.top = `${e.clientY}px`; 
   ripple.style.pointerEvents="none";
  
   ripple.style.animation = `ripple-effect .7s  ease-out`;
   ripple.onanimationend = () => document.body.removeChild(ripple);
  
}

