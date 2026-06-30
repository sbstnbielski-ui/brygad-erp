<?php

function spxProjectSelectEscape($value): string
{
    if (function_exists('e')) {
        return e((string)$value);
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function spxFetchSelectableProjects(PDO $pdo, bool $includeInternal = false): array
{
    $whereInternal = $includeInternal ? '' : 'AND p.is_internal = 0';
    $stmt = $pdo->query("
        SELECT
            p.id,
            p.name,
            p.status,
            p.project_type,
            p.investor_id,
            p.created_at,
            p.is_internal,
            i.name AS investor_name
        FROM projects p
        LEFT JOIN investors i ON i.id = p.investor_id
        WHERE p.status IN ('active', 'planned')
          AND p.archived_at IS NULL
          {$whereInternal}
        ORDER BY
            CASE p.project_type WHEN 'standard' THEN 1 WHEN 'micro' THEN 2 ELSE 3 END,
            CASE p.status WHEN 'active' THEN 1 WHEN 'planned' THEN 2 ELSE 3 END,
            p.created_at DESC,
            p.name ASC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function spxProjectTypeLabel(?string $projectType): string
{
    if ($projectType === 'micro') {
        return 'Mikroprojekty';
    }
    if ($projectType === 'standard' || $projectType === null || $projectType === '') {
        return 'Duże projekty';
    }
    return 'Inne projekty';
}

function spxProjectOptionLabel(array $project): string
{
    $label = trim((string)($project['name'] ?? ''));
    $details = [];

    if (!empty($project['investor_name'])) {
        $details[] = trim((string)$project['investor_name']);
    }
    if (($project['status'] ?? '') === 'planned') {
        $details[] = 'planowany';
    }

    if (!empty($details)) {
        $label .= ' — ' . implode(' / ', $details);
    }

    return $label;
}

function spxRenderProjectOptions(array $projects, $selectedId = null, string $emptyLabel = '-- Projekt --'): string
{
    $html = '<option value="">' . spxProjectSelectEscape($emptyLabel) . '</option>';
    $groups = [];

    foreach ($projects as $project) {
        $type = (string)($project['project_type'] ?? 'standard');
        $groups[$type][] = $project;
    }

    foreach ($groups as $type => $groupProjects) {
        $html .= '<optgroup label="' . spxProjectSelectEscape(spxProjectTypeLabel($type)) . '">';
        foreach ($groupProjects as $project) {
            $id = (int)($project['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $selected = ((string)$selectedId !== '' && (string)$selectedId === (string)$id) ? ' selected' : '';
            $html .= '<option value="' . $id . '"' . $selected . '>' . spxProjectSelectEscape(spxProjectOptionLabel($project)) . '</option>';
        }
        $html .= '</optgroup>';
    }

    return $html;
}
