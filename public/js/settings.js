(() => {
    'use strict';

    const form = document.querySelector('[data-store-settings]');
    if (!form) return;
    const preview = form.querySelector('[data-brand-preview]');
    const name = form.querySelector('[data-brand-name]');
    const tagline = form.querySelector('[data-brand-tagline]');
    const colors = form.querySelectorAll('[data-brand-color]');
    const fileInput = form.querySelector('[data-brand-logo-input]');
    const removeLogo = form.querySelector('[data-brand-remove-logo]');
    const previewName = form.querySelector('[data-brand-preview-name]');
    const previewTagline = form.querySelector('[data-brand-preview-tagline]');
    const previewInitials = form.querySelector('[data-brand-preview-initials]');
    const previewLogo = form.querySelector('[data-brand-preview-logo]');
    const currentLogo = previewLogo.dataset.currentLogo;
    let objectUrl = null;

    const initials = (value) => {
        const words = value.trim().split(/\s+/u).filter(Boolean);
        if (!words.length) return 'TT';
        let result = Array.from(words[0])[0];
        if (words.length > 1) result += Array.from(words[words.length - 1])[0];
        return result.toLocaleUpperCase('es-AR');
    };
    const render = () => {
        const storeName = name.value.trim() || 'Tu tienda';
        previewName.textContent = storeName;
        previewTagline.textContent = tagline.value.trim();
        previewTagline.hidden = !tagline.value.trim();
        previewInitials.textContent = initials(storeName);
        const selectedColor = Array.from(colors).find((input) => input.checked);
        if (selectedColor) preview.style.setProperty('--preview-color', selectedColor.value);

        const logoUrl = removeLogo?.checked ? '' : (objectUrl || currentLogo);
        previewLogo.hidden = !logoUrl;
        previewInitials.hidden = !!logoUrl;
        if (logoUrl) previewLogo.src = logoUrl;
        else previewLogo.removeAttribute('src');
    };
    const releasePreview = () => {
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = null;
    };
    name.addEventListener('input', render);
    tagline.addEventListener('input', render);
    colors.forEach((input) => input.addEventListener('change', render));
    fileInput.addEventListener('change', () => {
        releasePreview();
        fileInput.setCustomValidity('');
        const file = fileInput.files?.[0];
        if (file) {
            if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
                fileInput.setCustomValidity('Elegí un logo JPG, PNG o WebP de hasta 2 MB.');
                fileInput.reportValidity();
            } else {
                objectUrl = URL.createObjectURL(file);
                if (removeLogo) removeLogo.checked = false;
            }
        }
        render();
    });
    removeLogo?.addEventListener('change', () => {
        if (removeLogo.checked) {
            fileInput.value = '';
            fileInput.setCustomValidity('');
            releasePreview();
        }
        render();
    });
    window.addEventListener('pagehide', releasePreview);
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        const file = fileInput.files?.[0];
        if (file && fileInput.validity.valid) objectUrl = URL.createObjectURL(file);
        render();
    });
    render();
})();
