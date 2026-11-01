<?php if (!empty($partialView)) { return;} ?>
<?php if (current_user()) : ?>
    </main>
</div>
<?php else : ?>
    </main>
</div>
<footer class="auth-footer">ResolveIT &middot; CTS 4911 capstone project</footer>
<?php endif; ?>
</body>
</html>
