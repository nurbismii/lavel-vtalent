document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-password-toggle]');
    if (!button) return;
    const field = document.getElementById(button.dataset.passwordToggle);
    field.type = field.type === 'password' ? 'text' : 'password';
    button.textContent = field.type === 'password' ? 'Tampilkan password' : 'Sembunyikan password';
});
