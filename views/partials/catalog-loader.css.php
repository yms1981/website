<?php
// Salida CSS pura; layout.php la incluye dentro de <style> en <head> cuando $showCatalogLoader.
?>
@keyframes hv-shimmer {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}
@keyframes hv-orbit-spin {
  to { transform: rotate(360deg); }
}
@keyframes hv-pulse-soft {
  0%, 100% { transform: scale(0.92); opacity: 0.55; }
  50% { transform: scale(1.08); opacity: 1; }
}
@keyframes hv-catalog-card-in {
  from { opacity: 0; transform: translateY(14px) scale(0.97); }
  to { opacity: 1; transform: translateY(0) scale(1); }
}
@keyframes hv-bar-pulse {
  0%, 100% { filter: brightness(1); box-shadow: 0 0 12px rgba(255, 255, 255, 0.35); }
  50% { filter: brightness(1.08); box-shadow: 0 0 22px rgba(255, 255, 255, 0.55); }
}
@keyframes hv-red-glow {
  0%, 100% { opacity: 0.88; transform: scale(1); }
  50% { opacity: 1; transform: scale(1.02); }
}
@keyframes hv-shimmer-light {
  0% { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}
html.hv-catalog-load-lock,
body.hv-catalog-load-lock {
  overflow: hidden;
  overscroll-behavior: none;
}
.hv-catalog-load-outer {
  display: flex;
  justify-content: center;
  padding: 0.75rem 1rem 2.25rem;
}
/* Pantalla roja full-screen; por encima de header (z-50); por debajo del loader global API (99999). */
#hv-catalog-loading.hv-catalog-load-outer,
#hv-login-loading.hv-catalog-load-outer {
  position: fixed;
  inset: 0;
  z-index: 99980;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  padding: 1.25rem 1rem 2rem;
  margin: 0;
  background: #450a0a;
  overflow-y: auto;
  overscroll-behavior: contain;
  -webkit-overflow-scrolling: touch;
}
#hv-catalog-loading.hv-catalog-load-outer::before,
#hv-login-loading.hv-catalog-load-outer::before {
  content: '';
  position: absolute;
  inset: 0;
  z-index: 0;
  pointer-events: none;
  background:
    radial-gradient(ellipse 90% 55% at 50% -5%, rgba(254, 202, 202, 0.22) 0%, transparent 52%),
    radial-gradient(ellipse 70% 45% at 100% 60%, rgba(220, 38, 38, 0.45) 0%, transparent 48%),
    radial-gradient(ellipse 60% 40% at 0% 85%, rgba(127, 29, 29, 0.55) 0%, transparent 45%),
    linear-gradient(155deg, #2a0a0a 0%, #7f1d1d 38%, #b91c1c 58%, #991b1b 82%, #450a0a 100%);
  animation: hv-red-glow 10s ease-in-out infinite;
}
.hv-catalog-load-panel {
  position: relative;
  z-index: 1;
  width: 100%;
  max-width: 28rem;
  margin-left: auto;
  margin-right: auto;
}
.hv-catalog-load-card {
  position: relative;
  width: 100%;
  min-height: 18rem;
  padding: 2rem 1.75rem 1.5rem;
  background: linear-gradient(
    155deg,
    rgba(255, 255, 255, 0.16) 0%,
    rgba(255, 255, 255, 0.06) 48%,
    rgba(0, 0, 0, 0.12) 100%
  );
  border: 1px solid rgba(255, 255, 255, 0.28);
  border-radius: 1.5rem;
  box-shadow:
    0 0 0 1px rgba(255, 255, 255, 0.1) inset,
    0 4px 24px rgba(0, 0, 0, 0.2),
    0 0 80px rgba(220, 38, 38, 0.25);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  animation: hv-catalog-card-in 0.6s cubic-bezier(0.22, 1, 0.36, 1) both;
}
.hv-catalog-load-brand {
  text-align: center;
  margin: 0 0 1.125rem;
}
.hv-catalog-load-logo {
  display: inline-block;
  height: auto;
  max-height: 3.5rem;
  width: auto;
  max-width: 12.5rem;
  object-fit: contain;
  vertical-align: middle;
  filter: drop-shadow(0 2px 14px rgba(0, 0, 0, 0.35)) drop-shadow(0 0 24px rgba(255, 255, 255, 0.12));
}
.hv-catalog-load-logo--success {
  max-height: 2.25rem;
  max-width: 9rem;
  margin-bottom: 0.125rem;
  opacity: 0.98;
}
.hv-catalog-load-brand-text {
  margin: 0;
  font-size: 1.625rem;
  font-weight: 700;
  color: rgba(255, 255, 255, 0.98);
  letter-spacing: -0.02em;
  text-shadow: 0 1px 14px rgba(0, 0, 0, 0.3);
}
.hv-catalog-load-visual {
  position: relative;
  width: 4.75rem;
  height: 4.75rem;
  margin: 0 auto 1.125rem;
}
.hv-catalog-orbit {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}
.hv-catalog-orbit-ring {
  position: absolute;
  width: 100%;
  height: 100%;
  border-radius: 50%;
  border: 3px solid transparent;
  border-top-color: rgba(255, 255, 255, 0.95);
  border-right-color: rgba(255, 255, 255, 0.22);
  filter: drop-shadow(0 0 6px rgba(255, 255, 255, 0.35));
  animation: hv-orbit-spin 1.05s cubic-bezier(0.45, 0.05, 0.55, 0.95) infinite;
}
.hv-catalog-orbit-ring--delay {
  width: 70%;
  height: 70%;
  border-top-color: rgba(254, 226, 226, 0.9);
  border-right-color: rgba(255, 255, 255, 0.15);
  animation-duration: 0.75s;
  animation-direction: reverse;
}
.hv-catalog-load-pulse {
  position: absolute;
  inset: 22%;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(255, 255, 255, 0.45) 0%, rgba(255, 255, 255, 0.08) 42%, transparent 72%);
  animation: hv-pulse-soft 2.2s ease-in-out infinite;
  pointer-events: none;
}
.hv-catalog-load-eyebrow {
  text-align: center;
  font-size: 0.625rem;
  font-weight: 700;
  letter-spacing: 0.28em;
  text-transform: uppercase;
  color: rgba(255, 255, 255, 0.95);
  text-shadow: 0 1px 12px rgba(0, 0, 0, 0.25);
  margin: 0 0 1rem;
}
.hv-catalog-load-bar-wrap {
  padding: 0 0.125rem;
  margin-bottom: 0.75rem;
}
.hv-catalog-load-bar-track {
  height: 11px;
  border-radius: 9999px;
  background: rgba(0, 0, 0, 0.28);
  overflow: hidden;
  box-shadow: inset 0 1px 4px rgba(0, 0, 0, 0.35);
  border: 1px solid rgba(255, 255, 255, 0.12);
}
.hv-catalog-load-bar-fill {
  height: 100%;
  width: 8%;
  border-radius: 9999px;
  background: linear-gradient(90deg, #fef2f2, #ffffff, #fecaca, #ffffff, #fef2f2);
  background-size: 220% 100%;
  transition: width 0.5s cubic-bezier(0.33, 1, 0.68, 1);
  animation: hv-bar-pulse 1.4s ease-in-out infinite, hv-shimmer-light 2s ease-in-out infinite;
}
.hv-catalog-load-bar-fill.hv-catalog-load-bar--done {
  animation: none;
  width: 100% !important;
  background: linear-gradient(90deg, #bbf7d0, #4ade80, #22c55e, #16a34a);
  box-shadow: 0 0 18px rgba(74, 222, 128, 0.55);
}
.hv-catalog-load-bar-fill.hv-catalog-load-bar--error {
  animation: none;
  background: linear-gradient(90deg, rgba(255, 255, 255, 0.35), rgba(255, 255, 255, 0.55));
  box-shadow: none;
}
.hv-catalog-load-status {
  text-align: center;
  font-size: 0.9375rem;
  font-weight: 500;
  color: rgba(255, 255, 255, 0.92);
  line-height: 1.55;
  margin: 0 auto;
  padding: 0 0.5rem;
  max-width: 17rem;
  min-height: 2.75rem;
  text-shadow: 0 1px 8px rgba(0, 0, 0, 0.2);
}
.hv-catalog-mini-skel {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 0.5rem;
  margin-top: 1rem;
  flex-wrap: wrap;
}
.hv-mini-skel {
  width: 2.625rem;
  height: 2.625rem;
  border-radius: 0.5rem;
  flex-shrink: 0;
}
.hv-skeleton-shine {
  background: linear-gradient(
    110deg,
    rgba(255, 255, 255, 0.12) 0%,
    rgba(255, 255, 255, 0.38) 35%,
    rgba(255, 255, 255, 0.2) 50%,
    rgba(255, 255, 255, 0.38) 65%,
    rgba(255, 255, 255, 0.12) 100%
  );
  background-size: 220% 100%;
  animation: hv-shimmer 1.25s ease-in-out infinite;
  border: 1px solid rgba(255, 255, 255, 0.15);
}
.hv-catalog-load-success {
  display: none;
  position: absolute;
  left: 0;
  right: 0;
  top: 0;
  bottom: 0;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.875rem;
  padding: 1.5rem;
  text-align: center;
  background: linear-gradient(165deg, rgba(69, 10, 10, 0.88) 0%, rgba(127, 29, 29, 0.82) 100%);
  border-radius: 1.5rem;
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
}
.hv-catalog-load-success.hv-catalog-load-success--on {
  display: flex;
  animation: hv-success-in 0.55s cubic-bezier(0.34, 1.4, 0.64, 1) forwards;
}
@keyframes hv-success-in {
  from { opacity: 0; transform: scale(0.94); }
  to { opacity: 1; transform: scale(1); }
}
.hv-catalog-load-success-icon {
  width: 3.75rem;
  height: 3.75rem;
  border-radius: 50%;
  background: linear-gradient(145deg, #4ade80, #16a34a);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 12px 32px rgba(34, 197, 94, 0.45), 0 0 0 3px rgba(255, 255, 255, 0.25);
}
.hv-catalog-load-success-icon svg {
  width: 1.875rem;
  height: 1.875rem;
  stroke-width: 2.5;
}
.hv-catalog-load-success-title {
  font-size: 1.1875rem;
  font-weight: 700;
  color: rgba(255, 255, 255, 0.98);
  letter-spacing: -0.02em;
  text-shadow: 0 1px 10px rgba(0, 0, 0, 0.25);
}
#hv-catalog-loading.hv-catalog-loading--exit,
#hv-login-loading.hv-catalog-loading--exit {
  opacity: 0;
  transform: translateY(-8px);
  transition: opacity 0.5s ease, transform 0.5s ease;
  pointer-events: none;
}
.hv-catalog-load-card--error .hv-catalog-orbit-ring {
  animation-play-state: paused;
  border-top-color: rgba(255, 255, 255, 0.45) !important;
  border-right-color: rgba(255, 255, 255, 0.12) !important;
  filter: none;
}
.hv-catalog-load-card--error .hv-catalog-orbit-ring--delay {
  border-top-color: rgba(254, 202, 202, 0.4) !important;
}
.hv-catalog-load-card--error .hv-catalog-load-pulse {
  animation: none;
  opacity: 0.4;
  background: radial-gradient(circle, rgba(255, 255, 255, 0.2) 0%, transparent 70%);
}
.hv-catalog-load-card--error .hv-catalog-load-eyebrow {
  color: rgba(255, 255, 255, 0.75);
}
.hv-catalog-load-card--error .hv-catalog-load-status {
  color: rgba(255, 255, 255, 0.85);
}

/* Login: bloque de varias líneas de estado */
.hv-login-load-status-stack {
  margin: 0 auto;
  padding: 0 0.5rem;
  max-width: 20rem;
  text-align: center;
}
p.hv-login-load-line {
  margin: 0.45rem 0 0;
  font-size: 0.875rem;
  font-weight: 500;
  color: rgba(255, 255, 255, 0.88);
  line-height: 1.5;
  text-shadow: 0 1px 8px rgba(0, 0, 0, 0.2);
  transition: color 0.35s ease, opacity 0.35s ease;
}
p.hv-login-load-line:first-child {
  margin-top: 0;
  font-size: 0.9375rem;
  color: rgba(255, 255, 255, 0.95);
}
p.hv-login-load-line.hv-login-load-line--active {
  color: rgba(255, 255, 255, 1);
  opacity: 1;
}
p.hv-login-load-line.hv-login-load-line--dim {
  opacity: 0.72;
}
