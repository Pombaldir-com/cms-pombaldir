<?php
$useDataTables = $useDataTables ?? false;
?>
        </div>
    </div>
</div>
<script src="vendors/jquery/dist/jquery.min.js"></script>
<script src="vendors/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($useDataTables): ?>
<script src="vendors/datatables.net/js/dataTables.min.js"></script>
<script src="vendors/datatables.net-bs5/js/dataTables.bootstrap5.min.js"></script>
<script src="vendors/datatables.net-responsive/js/dataTables.responsive.min.js"></script>
<script src="vendors/datatables.net-responsive-bs5/js/responsive.bootstrap5.min.js"></script>
<?php endif; ?>
<script src="assets/js/custom.js"></script>
<?php if (!empty($pageScripts ?? '')): ?>
<script>
<?= $pageScripts ?>
</script>
<?php endif; ?>
</body>
</html>
