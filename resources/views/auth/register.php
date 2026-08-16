<div class="auth">
    <div class="auth__inner">
        <div class="auth__logo">
            <svg viewBox="0 0 64 64" aria-hidden="true"><use href="#sym-trophy"></use></svg>
            <h1>ROYAL SPIN</h1>
            <p>Create your account to play.</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert--error"><?= e($error) ?></div>
        <?php endif; ?>

        <div class="card">
            <h2>Create account</h2>
            <form method="post" action="/register" novalidate>
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <div class="field">
                    <label for="username">Username</label>
                    <input id="username" name="username" type="text" autocomplete="username" required
                           minlength="3" maxlength="20" autocapitalize="none" spellcheck="false">
                    <p class="field__hint">3–20 characters. Letters, numbers, dot, dash, underscore.</p>
                </div>
                <div class="field">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" autocomplete="email" required
                           autocapitalize="none" spellcheck="false">
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" autocomplete="new-password"
                           required minlength="8">
                    <p class="field__hint">At least 8 characters.</p>
                </div>
                <div class="field">
                    <label for="password_confirmation">Repeat password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password"
                           autocomplete="new-password" required minlength="8">
                </div>
                <button type="submit" class="button button--primary button--block button--huge">Create account</button>
            </form>
            <p class="auth__switch">Already registered? <a href="/login">Sign in</a></p>
        </div>
    </div>
</div>
