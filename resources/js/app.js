// Flux owns the preference and system-theme listener. Animate only an explicit choice.
window.agosChangeAppearance = function (mode, button, apply) {
    const root = document.documentElement;
    const dark = mode === 'dark' || (mode === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
    if (root.classList.contains('agos-theme-changing')) return;

    if (root.classList.contains('dark') === dark || !document.startViewTransition ||
        matchMedia('(prefers-reduced-motion: reduce)').matches) {
        apply(mode);
        return;
    }

    const { left, top, width, height } = button.getBoundingClientRect();
    const x = left + width / 2;
    const y = top + height / 2;
    const radius = Math.hypot(Math.max(x, innerWidth - x), Math.max(y, innerHeight - y));

    root.classList.add('agos-theme-changing');
    const transition = document.startViewTransition(() => apply(mode));
    transition.ready.then(() => root.animate({
        clipPath: [`circle(0px at ${x}px ${y}px)`, `circle(${radius}px at ${x}px ${y}px)`],
    }, {
        duration: 760,
        easing: 'cubic-bezier(0.4, 0, 0.2, 1)',
        pseudoElement: '::view-transition-new(root)',
    })).catch(() => {}); // The browser may skip the animation on navigation.
    transition.finished.finally(() => root.classList.remove('agos-theme-changing'));
};
