<div class="auth">
    <div class="auth__inner center">
        <div class="card">
            <h1>Something went wrong</h1>
            <p class="dim">The action could not be completed. Please try again.</p>
            <?php if (!empty($message)): ?>
                <pre class="debug-panel"><?= e($message) ?></pre>
            <?php endif; ?>
            <a class="button button--primary button--block" href="<?= e(url('/dashboard')) ?>">Back to the dashboard</a>
        </div>
    </div>
</div>
