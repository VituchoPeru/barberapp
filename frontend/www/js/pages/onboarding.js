import { icon } from '../icons.js';

const slides = [...document.querySelectorAll('[data-slide]')];
const dots = [...document.querySelectorAll('[data-dots] span')];
const next = document.querySelector('[data-next]');
let index = 0;

document.querySelectorAll('[data-icon]').forEach((el) => { el.innerHTML = icon(el.dataset.icon); });

function finish() {
  try { localStorage.setItem('barberapp.onboarding', 'visto'); } catch { /* modo privado */ }
}

function show(i) {
  index = Math.max(0, Math.min(slides.length - 1, i));
  slides.forEach((s, n) => { s.hidden = n !== index; });
  dots.forEach((d, n) => d.setAttribute('aria-current', String(n === index)));
  next.textContent = index === slides.length - 1 ? 'Explorar barberías' : 'Siguiente';
}

next.addEventListener('click', () => {
  if (index < slides.length - 1) { show(index + 1); return; }
  finish();
  location.assign('inicio.html');
});
document.querySelectorAll('[data-finish]').forEach((a) => a.addEventListener('click', finish));

// Deslizar con el dedo y teclado.
let x0 = null;
document.addEventListener('touchstart', (e) => { x0 = e.touches[0].clientX; }, { passive: true });
document.addEventListener('touchend', (e) => {
  if (x0 === null) return;
  const dx = e.changedTouches[0].clientX - x0;
  if (Math.abs(dx) > 50) show(index + (dx < 0 ? 1 : -1));
  x0 = null;
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'ArrowRight') show(index + 1);
  if (e.key === 'ArrowLeft') show(index - 1);
});
show(0);
