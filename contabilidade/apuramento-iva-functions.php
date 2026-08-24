<?php
// Helpers partilhados pelas paginas da tarefa "Apuramento de IVA"
// (tarefas-apuramento-iva.php e tarefas-apuramento-iva-detalhes.php).
// Ver contabilidade/APURAMENTO_IVA.md.

/**
 * Empresas (entidades adquirentes) a que o utilizador tem acesso nesta tarefa.
 */
function getIvaTaskEntities(PDO $pdo, bool $isAdmin, int $userId): array {
    $periodicityColumn = hasColumn('accounting_entities', 'vat_periodicity') ? 'vat_periodicity' : "'mensal'";
    if ($isAdmin) {
        $stmt = $pdo->query(
            "SELECT id, nif, name, $periodicityColumn AS vat_periodicity FROM accounting_entities
             WHERE entity_type = 'acquirer'
             ORDER BY name ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $stmt = $pdo->prepare(
        "SELECT ae.id, ae.nif, ae.name, ae.$periodicityColumn AS vat_periodicity
         FROM accounting_entities ae
         INNER JOIN accounting_entity_admin_task_permissions aep
             ON aep.accounting_entity_id = ae.id
         WHERE ae.entity_type = 'acquirer'
           AND aep.permission_key = 'ctb_apuramento_iva'
           AND aep.user_id = ?
         ORDER BY ae.name ASC"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function buildVatPeriodLabel(string $periodType, int $year, int $ref): string {
    return $periodType === 'trimestral' ? "$year-T$ref" : sprintf('%04d-%02d', $year, $ref);
}

function getClosedVatPeriods(PDO $pdo, int $entityId): array {
    $stmt = $pdo->prepare(
        'SELECT period_label FROM accounting_vat_settlements WHERE accounting_entity_id = ?'
    );
    $stmt->execute([$entityId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}
