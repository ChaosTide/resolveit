<?php if (!empty($partialView)) { return;} ?>
<?php if (current_user()) : ?>
    </main>
    <footer class="app-footer">Need help? Visit the <a href="kb.php">Knowledge Base</a></footer>
<?php else : ?>
    </main>
</div>
<footer class="auth-footer">ResolveIT &middot; CTS 4911 capstone project</footer>
<?php endif; ?>
</body>
</html>
