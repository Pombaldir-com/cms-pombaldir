<?php
/**
 * Painel principal do CMS.
 *
 * Este script apresenta um painel simples com a lista de tipos de conteúdo e
 * taxonomias existentes, fornecendo links para gerir campos personalizados,
 * adicionar conteúdos e listar conteúdos de cada tipo. Também permite criar
 * novos tipos de conteúdo e taxonomias. Requer que o utilizador esteja
 * autenticado para aceder.
 */

// Usar as funções comuns
require_once __DIR__ . '/functions.php';

// Inicia sessão e verifica se o utilizador está autenticado
startSession();
requireLogin();

// Recupera os tipos de conteúdo e taxonomias existentes
$types = getContentTypes();
$taxonomies = getTaxonomies();

// Inclui o cabeçalho comum do template
require_once __DIR__ . '/header.php';

?>
<!-- Conteúdo da página -->
<div class="container-fluid">
    <h2 class="mt-3">Tipos de Conteúdo</h2>
    <table class="table table-striped datatable">
        <thead>
            <tr><th>Nome</th><th>Ações</th></tr>
        </thead>
        <tbody>
        <?php foreach ($types as $type): ?>
            <tr>
                <td><?php echo htmlspecialchars($type['name']); ?></td>
                <td>
                    <a href="custom_fields.php?type_id=<?php echo $type['id']; ?>">Campos</a> |
                    <a href="add_content.php?type_id=<?php echo $type['id']; ?>">Adicionar</a> |
                    <a href="list_content.php?type_id=<?php echo $type['id']; ?>">Listar</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="card p-3 mt-4">
        <h5>Criar novo tipo de conteúdo</h5>
        <form method="post" action="content_types.php">
            <div class="mb-3">
                <label class="form-label" for="name">Nome</label>
                <input type="text" class="form-control" id="name" name="name" required>
            </div>
            <button type="submit" class="btn btn-primary">Criar</button>
        </form>
    </div>
    <h2 class="mt-5">Taxonomias</h2>
    <table class="table table-striped datatable">
        <thead>
            <tr><th>Nome</th><th>Ações</th></tr>
        </thead>
        <tbody>
        <?php foreach ($taxonomies as $tax): ?>
            <tr>
                <td><?php echo htmlspecialchars($tax['name']); ?></td>
                <td><a href="taxonomies.php?taxonomy_id=<?php echo $tax['id']; ?>">Gerir termos</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="card p-3 mt-4">
        <h5>Criar nova taxonomia</h5>
        <form method="post" action="taxonomies.php">
            <div class="mb-3">
                <label class="form-label" for="tname">Nome</label>
                <input type="text" class="form-control" id="tname" name="name" required>
            </div>
            <button type="submit" class="btn btn-primary">Criar</button>
        </form>
    </div>
</div>

<?php
// Inclui o rodapé comum do template
require_once __DIR__ . '/footer.php';