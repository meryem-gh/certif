<?php
session_start();
session_regenerate_id(true);

// Reset session on fresh load
if (!isset($_POST['step'])) {
    session_unset();
}

$error = '';
$step = isset($_POST['step']) ? intval($_POST['step']) : 1;

// ── STEP 2 : look up student modules ─────────────────────────────────────────
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cod_etu = trim($_POST['cod_etu']);
    $cod_cne = trim($_POST['cod_cne']);
    $filiere = trim($_POST['filiere']);
    $cas      = trim($_POST['cas']);

    if (!preg_match('/^\d+$/', $cod_etu)) {
        $error = 'Le Code Apogée doit être numérique.';
        $step = 1;
    } elseif (empty($cod_cne)) {
        $error = 'Veuillez saisir votre Code CNE.';
        $step = 1;
    } elseif (empty($filiere)) {
        $error = 'Veuillez choisir votre filière.';
        $step = 1;
    } elseif (!in_array($cas, array('deces', 'medical', 'retard'))) {
        $error = 'Veuillez sélectionner le type de cas.';
        $step = 1;
    } else {
        try {
            $db = new PDO('sqlite:' . __DIR__ . '/data/students.db');
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stmt = $db->prepare(
                'SELECT cod_elp, lib_elp FROM inscriptions WHERE cod_etu = :cod ORDER BY lib_elp'
            );
            $stmt->execute(array(':cod' => intval($cod_etu)));
            $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($modules)) {
                $error = 'Aucun étudiant trouvé avec le Code Apogée : ' . htmlspecialchars($cod_etu) . '. Vérifiez votre saisie.';
                $step = 1;
            } else {
                // Store in session
                $_SESSION['cod_etu']  = $cod_etu;
                $_SESSION['cod_cne']  = $cod_cne;
                $_SESSION['filiere']  = $filiere;
                $_SESSION['cas']      = $cas;
                $_SESSION['nom']      = $modules[0]['lib_elp']; // will fetch separately
                // Fetch student name
                $sn = $db->prepare('SELECT nom, prenom FROM inscriptions WHERE cod_etu = :cod LIMIT 1');
                $sn->execute(array(':cod' => intval($cod_etu)));
                $info = $sn->fetch(PDO::FETCH_ASSOC);
                $_SESSION['nom']    = $info ? $info['nom'] : '';
                $_SESSION['prenom'] = $info ? $info['prenom'] : '';
                $_SESSION['modules'] = $modules;
            }
        } catch (PDOException $e) {
            $error = 'Erreur base de données. Contactez l\'administration.';
            $step = 1;
        }
    }
}

// ── STEP 3 : validate module selection + handle upload ───────────────────────
if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_SESSION['cod_etu'])) {
        $step = 1;
        $error = 'Session expirée. Veuillez recommencer.';
    } else {
        $cas             = $_SESSION['cas'];
        $selected        = isset($_POST['modules']) ? $_POST['modules'] : array();
        $max_modules     = ($cas === 'retard') ? 2 : 999;

        if (empty($selected)) {
            $error = 'Veuillez sélectionner au moins un module.';
            $step = 2;
        } elseif (count($selected) > $max_modules) {
            $error = 'Pour un simple retard, vous ne pouvez sélectionner que 2 modules maximum.';
            $step = 2;
        } else {
            // File upload
            $upload_ok = false;
            $upload_msg = '';
            $file_path = '';

            if (!isset($_FILES['justificatif']) || $_FILES['justificatif']['error'] === UPLOAD_ERR_NO_FILE) {
                $error = 'Veuillez joindre le justificatif requis.';
                $step = 2;
            } elseif ($_FILES['justificatif']['error'] !== UPLOAD_ERR_OK) {
                $error = 'Erreur lors du chargement du fichier.';
                $step = 2;
            } else {
                $allowed = array('application/pdf', 'image/jpeg', 'image/png', 'image/jpg');
                $ftype   = $_FILES['justificatif']['type'];
                $fsize   = $_FILES['justificatif']['size'];
                $ext     = strtolower(pathinfo($_FILES['justificatif']['name'], PATHINFO_EXTENSION));
                $allowed_ext = array('pdf', 'jpg', 'jpeg', 'png');

                if (!in_array($ext, $allowed_ext)) {
                    $error = 'Format non autorisé. Acceptés : PDF, JPG, PNG.';
                    $step = 2;
                } elseif ($fsize > 5 * 1024 * 1024) {
                    $error = 'Le fichier ne doit pas dépasser 5 Mo.';
                    $step = 2;
                } else {
                    $new_name = $_SESSION['cod_etu'] . '_' . time() . '.' . $ext;
                    $dest = __DIR__ . '/uploads/' . $new_name;
                    if (move_uploaded_file($_FILES['justificatif']['tmp_name'], $dest)) {
                        $_SESSION['uploaded_file'] = $new_name;
                        $_SESSION['selected_modules'] = $selected;
                        $upload_ok = true;
                    } else {
                        $error = 'Impossible de sauvegarder le fichier. Contactez l\'administration.';
                        $step = 2;
                    }
                }
            }

            if ($upload_ok) {
                // Save demande to DB
                try {
                    $db = new PDO('sqlite:' . __DIR__ . '/data/students.db');
                    $db->exec("CREATE TABLE IF NOT EXISTS demandes (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        cod_etu INTEGER,
                        cod_cne TEXT,
                        filiere TEXT,
                        nom TEXT,
                        prenom TEXT,
                        cas TEXT,
                        modules TEXT,
                        fichier TEXT,
                        date_soumission TEXT
                    )");
                    $stmt = $db->prepare("INSERT INTO demandes
                        (cod_etu, cod_cne, filiere, nom, prenom, cas, modules, fichier, date_soumission)
                        VALUES (:etu,:cne,:fil,:nom,:pre,:cas,:mod,:fic,:dat)");
                    $stmt->execute(array(
                        ':etu' => $_SESSION['cod_etu'],
                        ':cne' => $_SESSION['cod_cne'],
                        ':fil' => $_SESSION['filiere'],
                        ':nom' => $_SESSION['nom'],
                        ':pre' => $_SESSION['prenom'],
                        ':cas' => $cas,
                        ':mod' => implode(',', $selected),
                        ':fic' => $new_name,
                        ':dat' => date('Y-m-d H:i:s'),
                    ));
                    $step = 4; // confirmation
                } catch (PDOException $e) {
                    $error = 'Erreur lors de l\'enregistrement.';
                    $step = 2;
                }
            }
        }
    }
}

// ── Filières list ────────────────────────────────────────────────────────────
$filieres = array(
    'HLGE' => 'Gestion des Entreprises',
    'HLEC' => 'Économie',
    'HLDR' => 'Droit',
    'HLSO' => 'Sociologie',
    'HLAA' => 'Administration des Affaires',
    'HLAC' => 'Comptabilité',
    'HLAF' => 'Finance',
    'HLAI' => 'Informatique de Gestion',
    'HLAJ' => 'Juridique',
    'HLAP' => 'Administration Publique',
    'HLAV' => 'Actuariat',
    'HLBA' => 'Banque Assurance',
    'HLCA' => 'Commerce et Affaires',
    'HLCF' => 'Comptabilité et Finance',
    'HLCM' => 'Commerce et Marketing',
    'HLDP' => 'Droit Privé',
    'HLEI' => 'Économie Internationale',
    'HLEM' => 'Économie et Management',
    'HLER' => 'Économie et Ressources',
    'HLFA' => 'Finance et Assurance',
    'HLFB' => 'Finance Bancaire',
    'HLFC' => 'Finance Contrôle',
    'HLFF' => 'Finance',
    'HLFI' => 'Finance Internationale',
    'HLFP' => 'Finance Publique',
    'HLFV' => 'Finance Verte',
    'HLGO' => 'Gestion des Organisations',
    'HLMA' => 'Management',
    'HLPE' => 'Politique Économique',
    'HLRH' => 'Ressources Humaines',
    'HBB1' => 'Licence 1 - Bilingue',
    'HBB2' => 'Licence 2 - Bilingue',
    'HBB3' => 'Licence 3 - Bilingue',
    'HFA1' => 'Licence 1 - Finance',
    'HFA2' => 'Licence 2 - Finance',
    'HFA3' => 'Licence 3 - Finance',
    'HFE1' => 'Licence 1 - Économie',
    'HFE2' => 'Licence 2 - Économie',
    'HFE3' => 'Licence 3 - Économie',
    'HFF1' => 'Licence 1 - Gestion',
    'HFF2' => 'Licence 2 - Gestion',
    'HFF3' => 'Licence 3 - Gestion',
    'HFG3' => 'Licence 3 - Gestion (G)',
    'HFP3' => 'Licence 3 - Professionnel',
    'HFB3' => 'Licence 3 - Banque',
);
asort($filieres);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demande de session de rattrapage — FSEJS Ain Chock</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #f0f4f8;
            color: #2d3748;
            min-height: 100vh;
        }
        header {
            background: linear-gradient(135deg, #1a365d 0%, #2b6cb0 100%);
            color: #fff;
            padding: 20px 0;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        header img { height: 70px; vertical-align: middle; margin-right: 15px; }
        header h1 { display: inline-block; vertical-align: middle; font-size: 1.3rem; line-height: 1.4; }
        header p { font-size: 0.9rem; opacity: 0.85; margin-top: 6px; }

        .container {
            max-width: 780px;
            margin: 40px auto;
            padding: 0 16px;
        }

        /* Steps indicator */
        .steps {
            display: flex;
            justify-content: center;
            margin-bottom: 32px;
            gap: 0;
        }
        .step-item {
            display: flex;
            align-items: center;
            font-size: 0.82rem;
            color: #a0aec0;
        }
        .step-item .num {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: #cbd5e0;
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-weight: bold;
            margin-right: 6px;
            flex-shrink: 0;
        }
        .step-item.active .num { background: #2b6cb0; }
        .step-item.done .num   { background: #38a169; }
        .step-item .label { white-space: nowrap; }
        .step-line {
            width: 48px; height: 2px;
            background: #cbd5e0;
            margin: 0 4px;
            align-self: center;
            flex-shrink: 0;
        }
        .step-line.done { background: #38a169; }

        .card {
            background: #fff;
            border-radius: 12px;
            padding: 36px 40px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        .card h2 {
            font-size: 1.25rem;
            color: #1a365d;
            margin-bottom: 24px;
            padding-bottom: 12px;
            border-bottom: 2px solid #ebf4ff;
        }

        .form-group { margin-bottom: 20px; }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            font-size: 0.92rem;
            color: #4a5568;
        }
        input[type="text"], input[type="number"], select {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #cbd5e0;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color .2s;
            background: #fff;
        }
        input:focus, select:focus {
            outline: none;
            border-color: #2b6cb0;
            box-shadow: 0 0 0 3px rgba(43,108,176,0.12);
        }

        /* Cas radio */
        .cas-options {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-top: 4px;
        }
        .cas-option input[type="radio"] { display: none; }
        .cas-option label {
            display: block;
            padding: 14px 10px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            text-align: center;
            cursor: pointer;
            transition: all .2s;
            font-weight: 600;
            font-size: 0.88rem;
        }
        .cas-option label .icon { font-size: 1.8rem; display: block; margin-bottom: 6px; }
        .cas-option input:checked + label {
            border-color: #2b6cb0;
            background: #ebf4ff;
            color: #1a365d;
        }
        .cas-option label:hover { border-color: #90cdf4; background: #f7faff; }

        /* Modules */
        .modules-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 8px;
        }
        .module-item {
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px 12px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            cursor: pointer;
            transition: all .15s;
        }
        .module-item:hover { border-color: #90cdf4; background: #f7faff; }
        .module-item.selected { border-color: #2b6cb0; background: #ebf4ff; }
        .module-item input[type="checkbox"] {
            width: 18px; height: 18px;
            flex-shrink: 0;
            margin-top: 2px;
            cursor: pointer;
            accent-color: #2b6cb0;
        }
        .module-item label {
            cursor: pointer;
            font-weight: 500;
            font-size: 0.88rem;
            color: #2d3748;
            margin: 0;
        }
        .module-item .cod { font-size: 0.75rem; color: #718096; margin-top: 2px; }

        .retard-note {
            background: #fffbeb;
            border: 1px solid #f6e05e;
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 14px;
            font-size: 0.875rem;
            color: #744210;
        }
        .retard-note strong { color: #b7791f; }

        /* Upload zone */
        .upload-zone {
            border: 2px dashed #cbd5e0;
            border-radius: 10px;
            padding: 24px;
            text-align: center;
            background: #f7faff;
            cursor: pointer;
            transition: all .2s;
        }
        .upload-zone:hover { border-color: #2b6cb0; background: #ebf4ff; }
        .upload-zone .upload-icon { font-size: 2.5rem; margin-bottom: 8px; }
        .upload-zone p { font-size: 0.88rem; color: #718096; }
        .upload-zone input[type="file"] { display: none; }
        #file-name {
            margin-top: 8px;
            font-size: 0.85rem;
            color: #2b6cb0;
            font-weight: 600;
        }

        .btn {
            display: inline-block;
            padding: 12px 32px;
            background: #2b6cb0;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s;
            text-decoration: none;
        }
        .btn:hover { background: #1a4e8a; }
        .btn-block { width: 100%; text-align: center; padding: 13px; }
        .btn-secondary {
            background: #e2e8f0;
            color: #4a5568;
        }
        .btn-secondary:hover { background: #cbd5e0; }

        .error-box {
            background: #fff5f5;
            border: 1.5px solid #fc8181;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
            color: #c53030;
            font-size: 0.9rem;
        }

        .student-info {
            background: #f0fff4;
            border: 1px solid #9ae6b4;
            border-radius: 8px;
            padding: 14px 18px;
            margin-bottom: 20px;
        }
        .student-info h3 { color: #276749; font-size: 1rem; margin-bottom: 4px; }
        .student-info p  { font-size: 0.88rem; color: #2d3748; }
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 700;
        }
        .badge-deces   { background: #fed7d7; color: #9b2c2c; }
        .badge-medical { background: #bee3f8; color: #1a365d; }
        .badge-retard  { background: #fefcbf; color: #7b341e; }

        /* Confirmation */
        .confirm-box {
            text-align: center;
            padding: 20px 0;
        }
        .confirm-icon { font-size: 5rem; }
        .confirm-box h2 { color: #276749; font-size: 1.5rem; margin: 16px 0 10px; }
        .confirm-box p  { color: #718096; font-size: 0.95rem; line-height: 1.6; }
        .confirm-ref {
            background: #f7faff;
            border: 1.5px solid #bee3f8;
            border-radius: 8px;
            padding: 14px 20px;
            margin: 20px auto;
            max-width: 340px;
            font-size: 0.9rem;
            color: #2d3748;
        }
        .confirm-ref strong { display: block; color: #1a365d; font-size: 1rem; }

        footer {
            text-align: center;
            padding: 24px;
            font-size: 0.8rem;
            color: #a0aec0;
        }

        @media (max-width: 600px) {
            .card { padding: 24px 18px; }
            .modules-grid { grid-template-columns: 1fr; }
            .cas-options { grid-template-columns: 1fr; }
            .step-line { width: 24px; }
        }
    </style>
</head>
<body>

<header>
    <div>
        <h1>Faculté des Sciences Économiques, Juridiques et Sociales<br>
            <span style="font-size:.9rem;font-weight:400;">Université Hassan II — Casablanca, Ain Chock</span>
        </h1>
        <p>Demande de passage en session de rattrapage — Empêchement à la session normale</p>
    </div>
</header>

<div class="container">

    <!-- Steps indicator -->
    <div class="steps">
        <div class="step-item <?php echo $step >= 1 ? ($step > 1 ? 'done' : 'active') : ''; ?>">
            <div class="num"><?php echo $step > 1 ? '✓' : '1'; ?></div>
            <div class="label">Identification</div>
        </div>
        <div class="step-line <?php echo $step > 1 ? 'done' : ''; ?>"></div>
        <div class="step-item <?php echo $step >= 2 ? ($step > 2 ? 'done' : 'active') : ''; ?>">
            <div class="num"><?php echo $step > 2 ? '✓' : '2'; ?></div>
            <div class="label">Modules &amp; justificatif</div>
        </div>
        <div class="step-line <?php echo $step > 2 ? 'done' : ''; ?>"></div>
        <div class="step-item <?php echo $step >= 4 ? 'done' : ($step == 3 ? 'active' : ''); ?>">
            <div class="num"><?php echo $step >= 4 ? '✓' : '3'; ?></div>
            <div class="label">Confirmation</div>
        </div>
    </div>

    <?php if ($step === 1): ?>
    <!-- ═══════════════════════════════════════════════════════════════
         STEP 1 : Student identification
         ═══════════════════════════════════════════════════════════════ -->
    <div class="card">
        <h2>&#128100; Identification de l'étudiant</h2>

        <?php if ($error): ?>
        <div class="error-box">&#9888; <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="index.php">
            <input type="hidden" name="step" value="2">

            <div class="form-group">
                <label for="cod_etu">Code Apogée *</label>
                <input type="text" id="cod_etu" name="cod_etu" placeholder="Ex: 23002065"
                    value="<?php echo htmlspecialchars(isset($_POST['cod_etu']) ? $_POST['cod_etu'] : ''); ?>"
                    maxlength="20" required>
            </div>

            <div class="form-group">
                <label for="cod_cne">Code CNE *</label>
                <input type="text" id="cod_cne" name="cod_cne" placeholder="Ex: G12345678"
                    value="<?php echo htmlspecialchars(isset($_POST['cod_cne']) ? $_POST['cod_cne'] : ''); ?>"
                    maxlength="20" required>
            </div>

            <div class="form-group">
                <label for="filiere">Filière *</label>
                <select id="filiere" name="filiere" required>
                    <option value="">-- Sélectionnez votre filière --</option>
                    <?php foreach ($filieres as $code => $lib): ?>
                    <option value="<?php echo htmlspecialchars($code); ?>"
                        <?php echo (isset($_POST['filiere']) && $_POST['filiere'] === $code) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($lib . ' (' . $code . ')'); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Motif de l'empêchement *</label>
                <div class="cas-options">
                    <div class="cas-option">
                        <input type="radio" name="cas" id="cas_deces" value="deces"
                            <?php echo (isset($_POST['cas']) && $_POST['cas'] === 'deces') ? 'checked' : ''; ?>>
                        <label for="cas_deces">
                            <span class="icon">&#128420;</span>
                            Cas de décès<br>
                            <small style="font-weight:400;color:#718096;">(acte de décès requis)</small>
                        </label>
                    </div>
                    <div class="cas-option">
                        <input type="radio" name="cas" id="cas_medical" value="medical"
                            <?php echo (isset($_POST['cas']) && $_POST['cas'] === 'medical') ? 'checked' : ''; ?>>
                        <label for="cas_medical">
                            <span class="icon">&#127973;</span>
                            Certificat médical<br>
                            <small style="font-weight:400;color:#718096;">(certificat médical requis)</small>
                        </label>
                    </div>
                    <div class="cas-option">
                        <input type="radio" name="cas" id="cas_retard" value="retard"
                            <?php echo (isset($_POST['cas']) && $_POST['cas'] === 'retard') ? 'checked' : ''; ?>>
                        <label for="cas_retard">
                            <span class="icon">&#9203;</span>
                            Simple retard<br>
                            <small style="font-weight:400;color:#718096;">(convocation requise — 2 modules max)</small>
                        </label>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-block">Rechercher mes modules &#8594;</button>
        </form>
    </div>

    <?php elseif ($step === 2): ?>
    <!-- ═══════════════════════════════════════════════════════════════
         STEP 2 : Modules + upload
         ═══════════════════════════════════════════════════════════════ -->
    <div class="card">
        <h2>&#128203; Sélection des modules et justificatif</h2>

        <?php if ($error): ?>
        <div class="error-box">&#9888; <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Student info recap -->
        <?php
        $cas_labels = array('deces' => 'Cas de décès', 'medical' => 'Certificat médical', 'retard' => 'Simple retard');
        $cas_now    = $_SESSION['cas'];
        ?>
        <div class="student-info">
            <h3>&#9989; Étudiant identifié</h3>
            <p>
                <strong><?php echo htmlspecialchars($_SESSION['prenom'] . ' ' . $_SESSION['nom']); ?></strong>
                &nbsp;|&nbsp; Apogée : <strong><?php echo htmlspecialchars($_SESSION['cod_etu']); ?></strong>
                &nbsp;|&nbsp; CNE : <strong><?php echo htmlspecialchars($_SESSION['cod_cne']); ?></strong>
                &nbsp;|&nbsp; Filière : <strong><?php echo htmlspecialchars($_SESSION['filiere']); ?></strong>
                &nbsp;&nbsp;
                <span class="badge badge-<?php echo $cas_now; ?>">
                    <?php echo $cas_labels[$cas_now]; ?>
                </span>
            </p>
        </div>

        <?php if ($cas_now === 'retard'): ?>
        <div class="retard-note">
            &#9888;&nbsp; <strong>Attention :</strong> En cas de simple retard, vous ne pouvez sélectionner
            que <strong>2 modules maximum</strong>.
        </div>
        <?php endif; ?>

        <form method="POST" action="index.php" enctype="multipart/form-data" id="formStep2">
            <input type="hidden" name="step" value="3">

            <div class="form-group">
                <label>Modules concernés * — cochez les modules que vous n'avez pas pu passer :</label>
                <div class="modules-grid" id="modulesGrid">
                    <?php foreach ($_SESSION['modules'] as $mod): ?>
                    <div class="module-item">
                        <input type="checkbox" name="modules[]"
                               value="<?php echo htmlspecialchars($mod['cod_elp']); ?>"
                               id="mod_<?php echo htmlspecialchars($mod['cod_elp']); ?>">
                        <label for="mod_<?php echo htmlspecialchars($mod['cod_elp']); ?>">
                            <?php echo htmlspecialchars($mod['lib_elp']); ?>
                            <div class="cod"><?php echo htmlspecialchars($mod['cod_elp']); ?></div>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group" style="margin-top:28px;">
                <?php
                $doc_labels = array(
                    'deces'   => 'Acte de décès (PDF, JPG ou PNG — max 5 Mo)',
                    'medical' => 'Certificat médical (PDF, JPG ou PNG — max 5 Mo)',
                    'retard'  => 'Convocation d\'examen (PDF, JPG ou PNG — max 5 Mo)',
                );
                $doc_icons = array('deces' => '&#128420;', 'medical' => '&#127973;', 'retard' => '&#128196;');
                ?>
                <label>
                    <?php echo $doc_icons[$cas_now]; ?>
                    Justificatif requis : <strong><?php echo $doc_labels[$cas_now]; ?></strong>
                </label>
                <div class="upload-zone" onclick="document.getElementById('justificatif').click()">
                    <input type="file" name="justificatif" id="justificatif"
                           accept=".pdf,.jpg,.jpeg,.png"
                           onchange="showFileName(this)">
                    <div class="upload-icon">&#128206;</div>
                    <p>Cliquez pour choisir un fichier<br>
                       <small>PDF, JPG, PNG — taille max : 5 Mo</small>
                    </p>
                    <div id="file-name"></div>
                </div>
            </div>

            <div style="display:flex;gap:12px;margin-top:10px;">
                <a href="index.php" class="btn btn-secondary" style="flex:1;text-align:center;padding:13px;">
                    &#8592; Recommencer
                </a>
                <button type="submit" class="btn" style="flex:2;" id="submitBtn">
                    Soumettre la demande &#8594;
                </button>
            </div>
        </form>
    </div>

    <?php elseif ($step === 4): ?>
    <!-- ═══════════════════════════════════════════════════════════════
         STEP 4 : Confirmation
         ═══════════════════════════════════════════════════════════════ -->
    <div class="card">
        <div class="confirm-box">
            <div class="confirm-icon">&#9989;</div>
            <h2>Demande soumise avec succès !</h2>
            <p>Votre demande de passage en session de rattrapage a bien été enregistrée.<br>
               Elle sera traitée par l'administration dans les meilleurs délais.</p>

            <div class="confirm-ref">
                <strong>Récapitulatif</strong>
                Étudiant : <?php echo htmlspecialchars($_SESSION['prenom'] . ' ' . $_SESSION['nom']); ?><br>
                Code Apogée : <?php echo htmlspecialchars($_SESSION['cod_etu']); ?><br>
                Motif : <?php echo htmlspecialchars($cas_labels[$_SESSION['cas']]); ?><br>
                Modules sélectionnés : <?php echo count($_SESSION['selected_modules']); ?><br>
                Date : <?php echo date('d/m/Y à H:i'); ?>
            </div>

            <p style="margin-top:16px;color:#9b2c2c;font-size:0.87rem;">
                &#9888; Conservez une copie de votre justificatif.
                Présentez-vous à l'administration muni de votre original.
            </p>

            <a href="index.php" class="btn" style="margin-top:24px;display:inline-block;">
                &#8592; Nouvelle demande
            </a>
        </div>
    </div>
    <?php endif; ?>

</div>

<footer>
    &copy; <?php echo date('Y'); ?> &mdash; FSEJS Ain Chock, Université Hassan II de Casablanca
</footer>

<script>
<?php if ($step === 2):
    $max = ($_SESSION['cas'] === 'retard') ? 2 : 999;
?>
var MAX_MODULES = <?php echo $max; ?>;

// Only the checkbox drives state — the card click just forwards to the checkbox
document.querySelectorAll('#modulesGrid .module-item').forEach(function(card) {
    card.addEventListener('click', function(e) {
        // If click originated from checkbox or its label, let the browser handle it natively
        if (e.target.type === 'checkbox' || e.target.tagName === 'LABEL') return;
        // Click on any other part of the card: manually toggle
        var cb = card.querySelector('input[type="checkbox"]');
        cb.checked = !cb.checked;
        cb.dispatchEvent(new Event('change'));
    });
});

document.querySelectorAll('#modulesGrid input[type="checkbox"]').forEach(function(cb) {
    cb.addEventListener('change', function() {
        var card = this.closest('.module-item');
        if (this.checked) {
            var checked = document.querySelectorAll('#modulesGrid input:checked').length;
            if (checked > MAX_MODULES) {
                this.checked = false;
                card.classList.remove('selected');
                alert('Vous ne pouvez sélectionner que ' + MAX_MODULES + ' module(s) maximum.');
                return;
            }
            card.classList.add('selected');
        } else {
            card.classList.remove('selected');
        }
    });
});

function showFileName(input) {
    var fn = document.getElementById('file-name');
    fn.textContent = input.files.length ? '✔ ' + input.files[0].name : '';
}

document.getElementById('formStep2').addEventListener('submit', function(e) {
    var checked = document.querySelectorAll('#modulesGrid input:checked').length;
    if (checked === 0) {
        e.preventDefault();
        alert('Veuillez sélectionner au moins un module.');
        return;
    }
    var file = document.getElementById('justificatif');
    if (!file.files.length) {
        e.preventDefault();
        alert('Veuillez joindre le justificatif requis.');
        return;
    }
    document.getElementById('submitBtn').disabled = true;
    document.getElementById('submitBtn').textContent = 'Envoi en cours...';
});
<?php endif; ?>
</script>

</body>
</html>
