import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';
import ScrollSmoother from 'gsap/ScrollSmoother';

export default function initArtZipper(){

	gsap.registerPlugin(ScrollTrigger, ScrollSmoother);

	// Animación zipper
	gsap.set(".zipper li > .zipper_target", {transformOrigin:"0 50%"})
	gsap.set(".zipper li:not(:first-of-type) .zipper_target", {opacity:0.2, scale:0.8})

	const tl = gsap.timeline()
		.to(".zipper li:not(:first-of-type) .zipper_target", 
			{opacity:1, scale:1, stagger:0.5}
			)
		.to(".zipper li:not(:last-of-type) .zipper_target", 
			{opacity:0.2, scale:0.8, stagger:0.5}, 0)


	ScrollTrigger.create({
		trigger:".zipper_follow", 
		start:"center center",
		endTrigger:".zipper li:last-of-type",
		end:"center center",
		pin:true,
		pinType: "transform",
		markers:false,
		animation:tl,
		scrub:true
	}) 

}
