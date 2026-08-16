<div class="auth">
    <div class="auth__inner">
        <div class="auth__logo">
            <svg viewBox="0 0 64 64" aria-hidden="true"><use href="#sym-crown"></use></svg>
            <h1>ROYAL SPIN</h1>
            <p>Slots, dice and upgrades — first to the target wins.</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert--error"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if (!empty($notice)): ?>
            <div class="alert alert--notice"><?= e($notice) ?></div>
        <?php endif; ?>

        <div class="card">
            <h2>Sign in</h2>
            <form method="post" action="<?= e(url('/login')) ?>" novalidate>
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <div class="field">
                    <label for="identifier">Username or email</label>
                    <input id="identifier" name="identifier" type="text" autocomplete="username"
                           required autocapitalize="none" spellcheck="false">
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                </div>
                <button type="submit" class="button button--primary button--block button--huge">Sign in</button>
            </form>
            <p class="auth__switch">No account yet? <a href="<?= e(url('/register')) ?>">Create one</a></p>
        </div>
    </div>
</div>
