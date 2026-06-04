<?php
// ── Simple password protection ────────────────────────────────────────────────
define('BO_PASSWORD', 'fsejs2025');   // Change this before production

session_start();

$auth_error = '';
if (isset($_POST['bo_password'])) {
    if ($_POST['bo_password'] === BO_PASSWORD) {
        $_SESSION['bo_auth'] = true;
    } else {
        $auth_error = 'Mot de passe incorrect.';
    }
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: backoffice.php');
    exit;
}

$authenticated = !empty($_SESSION['bo_auth']);

// ── DB connection ─────────────────────────────────────────────────────────────
function getDB() {
    $db = new PDO('sqlite:' . __DIR__ . '/data/students.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
    return $db;
}

// ── Excel export ──────────────────────────────────────────────────────────────
if ($authenticated && isset($_GET['export'])) {
    require __DIR__ . '/vendor/autoload.php';

    $db  = getDB();

    // Filters from query string
    $where  = array('1=1');
    $params = array();

    if (!empty($_GET['filtre_cas'])) {
        $where[]           = 'd.cas = :cas';
        $params[':cas']    = $_GET['filtre_cas'];
    }
    if (!empty($_GET['filtre_filiere'])) {
        $where[]              = 'd.filiere = :fil';
        $params[':fil']       = $_GET['filtre_filiere'];
    }
    if (!empty($_GET['filtre_date'])) {
        $where[]              = "substr(d.date_soumission,1,10) = :dat";
        $params[':dat']       = $_GET['filtre_date'];
    }

    $sql = 'SELECT d.*, i.DATE_NAI_IND, i.COD_EXT_GPE
            FROM demandes d
            LEFT JOIN inscriptions i ON i.cod_etu = d.cod_etu AND i.cod_elp = (
                SELECT cod_elp FROM inscriptions WHERE cod_etu = d.cod_etu LIMIT 1
            )
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY d.nom, d.prenom';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $demandes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build rows: one row per module (same structure as source Excel)
    $rows = array();
    foreach ($demandes as $dem) {
        $mods = explode(',', $dem['modules']);
        foreach ($mods as $cod_elp) {
            $cod_elp = trim($cod_elp);
            // Fetch module name and group from inscriptions
            $ms = $db->prepare('SELECT lib_elp, cod_ext_gpe FROM inscriptions WHERE cod_etu = :e AND cod_elp = :m LIMIT 1');
            $ms->execute(array(':e' => $dem['cod_etu'], ':m' => $cod_elp));
            $mod = $ms->fetch(PDO::FETCH_ASSOC);

            // Fetch date of birth
            $ds = $db->prepare('SELECT DATE_NAI_IND FROM inscriptions WHERE cod_etu = :e LIMIT 1');
            $ds->execute(array(':e' => $dem['cod_etu']));
            $dob_row = $ds->fetch(PDO::FETCH_ASSOC);

            $rows[] = array(
                'COD_ETU'        => $dem['cod_etu'],
                'LIB_NOM_PAT_IND'=> $dem['nom'],
                'LIB_PR1_IND'    => $dem['prenom'],
                'DATE_NAI_IND'   => $dob_row ? $dob_row['DATE_NAI_IND'] : '',
                'COD_ELP'        => $cod_elp,
                'LIB_ELP'        => $mod ? $mod['lib_elp'] : '',
                'COD_EXT_GPE'    => $mod ? $mod['cod_ext_gpe'] : '',
                'COD_TRE'        => '',   // empty — session rattrapage
            );
        }
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Rattrapage');

    // Headers (same as source file)
    $headers = array('COD_ETU','LIB_NOM_PAT_IND','LIB_PR1_IND','DATE_NAI_IND','COD_ELP','LIB_ELP','COD_EXT_GPE','COD_TRE');
    foreach ($headers as $col => $h) {
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1) . '1';
        $sheet->setCellValue($cell, $h);
    }

    // Style header
    $sheet->getStyle('A1:H1')->applyFromArray(array(
        'font' => array('bold' => true, 'color' => array('rgb' => 'FFFFFF')),
        'fill' => array('fillType' => 'solid', 'startColor' => array('rgb' => '1a365d')),
        'alignment' => array('horizontal' => 'center'),
        'borders' => array('allBorders' => array('borderStyle' => 'thin', 'color' => array('rgb' => 'AAAAAA'))),
    ));

    // Data rows
    foreach ($rows as $r => $row) {
        $rowNum = $r + 2;
        $vals   = array_values($row);
        foreach ($vals as $col => $val) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col + 1) . $rowNum;
            if ($col === 3 && $val) {
                // DATE_NAI_IND — format as date
                $ts = strtotime($val);
                if ($ts) {
                    $sheet->setCellValue($cell, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($ts));
                    $sheet->getStyle($cell)->getNumberFormat()
                          ->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_DATE_DDMMYYYY);
                } else {
                    $sheet->setCellValue($cell, $val);
                }
            } else {
                $sheet->setCellValue($cell, $val);
            }
        }
        // Zebra stripe
        if ($r % 2 === 1) {
            $sheet->getStyle('A'.$rowNum.':H'.$rowNum)->getFill()
                  ->setFillType('solid')->getStartColor()->setRGB('EBF4FF');
        }
    }

    // Auto-width
    foreach (range('A', 'H') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Freeze header
    $sheet->freezePane('A2');

    $filename = 'Rattrapage_' . date('Ymd_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// ── Fetch demandes for display ────────────────────────────────────────────────
$demandes = array();
$stats    = array('total' => 0, 'deces' => 0, 'medical' => 0, 'retard' => 0);

if ($authenticated) {
    $db = getDB();

    $where  = array('1=1');
    $params = array();
    if (!empty($_GET['filtre_cas'])) {
        $where[]        = 'cas = :cas';
        $params[':cas'] = $_GET['filtre_cas'];
    }
    if (!empty($_GET['filtre_filiere'])) {
        $where[]        = 'filiere = :fil';
        $params[':fil'] = $_GET['filtre_filiere'];
    }
    if (!empty($_GET['filtre_date'])) {
        $where[]        = "substr(date_soumission,1,10) = :dat";
        $params[':dat'] = $_GET['filtre_date'];
    }

    $stmt = $db->prepare('SELECT * FROM demandes WHERE ' . implode(' AND ', $where) . ' ORDER BY date_soumission DESC');
    $stmt->execute($params);
    $demandes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sc = $db->query("SELECT cas, COUNT(*) as n FROM demandes GROUP BY cas");
    foreach ($sc->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $stats[$r['cas']] = $r['n'];
    }
    $stats['total'] = array_sum(array($stats['deces'], $stats['medical'], $stats['retard']));
}

$cas_labels = array('deces' => 'Cas de décès', 'medical' => 'Certificat médical', 'retard' => 'Simple retard');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Back Office — Rattrapage FSEJS Ain Chock</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f4f8; color: #2d3748; min-height: 100vh; }

        /* ── Header ── */
        header {
            background: linear-gradient(135deg, #1a365d 0%, #2b6cb0 100%);
            color: #fff; padding: 16px 32px;
            display: flex; align-items: center; justify-content: space-between;
        }
        header h1 { font-size: 1.1rem; }
        header p  { font-size: .8rem; opacity: .8; margin-top: 2px; }
        .logout-btn {
            background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.3);
            color: #fff; padding: 6px 16px; border-radius: 6px; text-decoration: none;
            font-size: .85rem; cursor: pointer;
        }
        .logout-btn:hover { background: rgba(255,255,255,.25); }

        /* ── Login ── */
        .login-wrap {
            display: flex; align-items: center; justify-content: center;
            min-height: calc(100vh - 70px);
        }
        .login-card {
            background: #fff; border-radius: 12px;
            padding: 48px 40px; width: 360px;
            box-shadow: 0 4px 24px rgba(0,0,0,.1);
            text-align: center;
        }
        .login-card .lock { font-size: 3rem; margin-bottom: 12px; }
        .login-card h2   { color: #1a365d; margin-bottom: 24px; }

        /* ── Layout ── */
        .main { padding: 28px 32px; max-width: 1400px; margin: 0 auto; }

        /* ── Stats ── */
        .stats-row { display: flex; gap: 16px; margin-bottom: 28px; flex-wrap: wrap; }
        .stat-card {
            flex: 1; min-width: 140px;
            background: #fff; border-radius: 10px;
            padding: 18px 20px; text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,.06);
            border-top: 4px solid #2b6cb0;
        }
        .stat-card.deces   { border-color: #e53e3e; }
        .stat-card.medical { border-color: #3182ce; }
        .stat-card.retard  { border-color: #d69e2e; }
        .stat-card .num  { font-size: 2.2rem; font-weight: 700; color: #1a365d; }
        .stat-card .lbl  { font-size: .8rem; color: #718096; margin-top: 4px; }

        /* ── Toolbar ── */
        .toolbar {
            background: #fff; border-radius: 10px;
            padding: 16px 20px; margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,.06);
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
        }
        .toolbar label { font-size: .85rem; font-weight: 600; color: #4a5568; white-space: nowrap; }
        .toolbar select, .toolbar input[type=date] {
            padding: 7px 12px; border: 1.5px solid #e2e8f0;
            border-radius: 7px; font-size: .88rem; background: #f7faff;
        }
        .toolbar select:focus, .toolbar input:focus {
            outline: none; border-color: #2b6cb0;
        }
        .btn {
            padding: 8px 20px; border: none; border-radius: 7px;
            font-size: .88rem; font-weight: 600; cursor: pointer;
            text-decoration: none; display: inline-block;
        }
        .btn-primary { background: #2b6cb0; color: #fff; }
        .btn-primary:hover { background: #1a4e8a; }
        .btn-success { background: #38a169; color: #fff; }
        .btn-success:hover { background: #276749; }
        .btn-reset { background: #e2e8f0; color: #4a5568; }
        .btn-reset:hover { background: #cbd5e0; }

        /* ── Table ── */
        .table-wrap {
            background: #fff; border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,.06); overflow: hidden;
        }
        .table-header {
            padding: 16px 20px; border-bottom: 1px solid #e2e8f0;
            display: flex; justify-content: space-between; align-items: center;
        }
        .table-header h2 { font-size: 1rem; color: #1a365d; }
        .table-header span { font-size: .85rem; color: #718096; }
        table { width: 100%; border-collapse: collapse; }
        th {
            background: #f7faff; font-size: .78rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: .04em;
            color: #4a5568; padding: 10px 14px; text-align: left;
            border-bottom: 1.5px solid #e2e8f0;
        }
        td {
            padding: 11px 14px; font-size: .88rem;
            border-bottom: 1px solid #f0f4f8; vertical-align: middle;
        }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f7faff; }

        .badge {
            display: inline-block; padding: 3px 10px; border-radius: 20px;
            font-size: .75rem; font-weight: 700; white-space: nowrap;
        }
        .badge-deces   { background: #fed7d7; color: #9b2c2c; }
        .badge-medical { background: #bee3f8; color: #1a365d; }
        .badge-retard  { background: #fefcbf; color: #7b341e; }

        .modules-list { font-size: .8rem; color: #4a5568; line-height: 1.6; }
        .modules-list span {
            display: inline-block; background: #ebf4ff; color: #1a365d;
            border-radius: 4px; padding: 1px 7px; margin: 1px 2px;
            font-size: .75rem;
        }

        .doc-link {
            font-size: .8rem; color: #2b6cb0; text-decoration: none;
        }
        .doc-link:hover { text-decoration: underline; }

        .empty-state {
            text-align: center; padding: 60px 20px; color: #a0aec0;
        }
        .empty-state .icon { font-size: 3rem; margin-bottom: 12px; }

        /* ── Form shared ── */
        .form-group { margin-bottom: 18px; }
        input[type=text], input[type=password] {
            width: 100%; padding: 10px 14px;
            border: 1.5px solid #cbd5e0; border-radius: 8px; font-size: 1rem;
        }
        input:focus { outline: none; border-color: #2b6cb0; box-shadow: 0 0 0 3px rgba(43,108,176,.12); }
        .error-box {
            background: #fff5f5; border: 1.5px solid #fc8181; border-radius: 8px;
            padding: 10px 14px; margin-bottom: 16px; color: #c53030; font-size: .9rem;
        }
        footer { text-align: center; padding: 20px; font-size: .78rem; color: #a0aec0; }

        @media (max-width:768px) {
            .main { padding: 16px; }
            .toolbar { flex-direction: column; align-items: stretch; }
            th, td { padding: 8px 10px; }
        }
    </style>
</head>
<body>

<header>
    <div>
        <h1>&#128197; Back Office — Session de rattrapage</h1>
        <p>FSEJS Ain Chock &mdash; Université Hassan II de Casablanca</p>
    </div>
    <?php if ($authenticated): ?>
    <a href="backoffice.php?logout=1" class="logout-btn">&#128275; Déconnexion</a>
    <?php endif; ?>
</header>

<?php if (!$authenticated): ?>
<!-- ════════════════════════════════════════════════════
     LOGIN
════════════════════════════════════════════════════ -->
<div class="login-wrap">
    <div class="login-card">
        <div class="lock">&#128272;</div>
        <h2>Accès administration</h2>
        <?php if ($auth_error): ?>
        <div class="error-box">&#9888; <?php echo htmlspecialchars($auth_error); ?></div>
        <?php endif; ?>
        <form method="POST" action="backoffice.php">
            <div class="form-group">
                <input type="password" name="bo_password" placeholder="Mot de passe" autofocus required>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;padding:11px;">
                Se connecter &#8594;
            </button>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ════════════════════════════════════════════════════
     DASHBOARD
════════════════════════════════════════════════════ -->
<div class="main">

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="num"><?php echo $stats['total']; ?></div>
            <div class="lbl">Total demandes</div>
        </div>
        <div class="stat-card deces">
            <div class="num"><?php echo $stats['deces']; ?></div>
            <div class="lbl">&#128420; Cas de décès</div>
        </div>
        <div class="stat-card medical">
            <div class="num"><?php echo $stats['medical']; ?></div>
            <div class="lbl">&#127973; Certificats médicaux</div>
        </div>
        <div class="stat-card retard">
            <div class="num"><?php echo $stats['retard']; ?></div>
            <div class="lbl">&#9203; Simples retards</div>
        </div>
    </div>

    <!-- Toolbar / Filters -->
    <div class="toolbar">
        <form method="GET" action="backoffice.php" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;width:100%;">
            <label>Motif :</label>
            <select name="filtre_cas">
                <option value="">Tous</option>
                <option value="deces"   <?php echo (!empty($_GET['filtre_cas']) && $_GET['filtre_cas']==='deces')   ? 'selected' : ''; ?>>Décès</option>
                <option value="medical" <?php echo (!empty($_GET['filtre_cas']) && $_GET['filtre_cas']==='medical') ? 'selected' : ''; ?>>Médical</option>
                <option value="retard"  <?php echo (!empty($_GET['filtre_cas']) && $_GET['filtre_cas']==='retard')  ? 'selected' : ''; ?>>Retard</option>
            </select>

            <label>Filière :</label>
            <input type="text" name="filtre_filiere" placeholder="Ex: HLGE"
                   value="<?php echo htmlspecialchars($_GET['filtre_filiere'] ?? ''); ?>"
                   style="width:100px;">

            <label>Date :</label>
            <input type="date" name="filtre_date"
                   value="<?php echo htmlspecialchars($_GET['filtre_date'] ?? ''); ?>">

            <button type="submit" class="btn btn-primary">&#128269; Filtrer</button>
            <a href="backoffice.php" class="btn btn-reset">&#10006; Réinitialiser</a>

            <!-- Export button — passes current filters -->
            <a href="backoffice.php?export=1<?php
                if (!empty($_GET['filtre_cas']))     echo '&filtre_cas='     . urlencode($_GET['filtre_cas']);
                if (!empty($_GET['filtre_filiere'])) echo '&filtre_filiere=' . urlencode($_GET['filtre_filiere']);
                if (!empty($_GET['filtre_date']))    echo '&filtre_date='    . urlencode($_GET['filtre_date']);
            ?>" class="btn btn-success" style="margin-left:auto;">
                &#128196; Exporter Excel (.xlsx)
            </a>
        </form>
    </div>

    <!-- Table -->
    <div class="table-wrap">
        <div class="table-header">
            <h2>&#128203; Liste des demandes</h2>
            <span><?php echo count($demandes); ?> résultat<?php echo count($demandes) !== 1 ? 's' : ''; ?></span>
        </div>

        <?php if (empty($demandes)): ?>
        <div class="empty-state">
            <div class="icon">&#128202;</div>
            <p>Aucune demande enregistrée pour le moment.</p>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Code Apogée</th>
                    <th>CNE</th>
                    <th>Nom &amp; Prénom</th>
                    <th>Filière</th>
                    <th>Motif</th>
                    <th>Modules sélectionnés</th>
                    <th>Justificatif</th>
                    <th>Date soumission</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($demandes as $i => $d): ?>
            <tr>
                <td style="color:#a0aec0;font-size:.8rem;"><?php echo $d['id']; ?></td>
                <td><strong><?php echo htmlspecialchars($d['cod_etu']); ?></strong></td>
                <td><?php echo htmlspecialchars($d['cod_cne']); ?></td>
                <td>
                    <?php echo htmlspecialchars($d['prenom'] . ' ' . $d['nom']); ?>
                </td>
                <td style="font-size:.82rem;color:#4a5568;"><?php echo htmlspecialchars($d['filiere']); ?></td>
                <td>
                    <span class="badge badge-<?php echo $d['cas']; ?>">
                        <?php echo $cas_labels[$d['cas']]; ?>
                    </span>
                </td>
                <td>
                    <div class="modules-list">
                    <?php
                    $mods = explode(',', $d['modules']);
                    foreach ($mods as $m) {
                        echo '<span>' . htmlspecialchars(trim($m)) . '</span>';
                    }
                    ?>
                    </div>
                </td>
                <td>
                    <?php if ($d['fichier']): ?>
                    <a class="doc-link" href="uploads/<?php echo htmlspecialchars($d['fichier']); ?>" target="_blank">
                        &#128206; Voir
                    </a>
                    <?php else: ?>
                    <span style="color:#a0aec0;">—</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:.8rem;color:#718096;white-space:nowrap;">
                    <?php
                    $dt = $d['date_soumission'];
                    if ($dt) echo date('d/m/Y H:i', strtotime($dt));
                    ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

</div>
<?php endif; ?>

<footer>
    &copy; <?php echo date('Y'); ?> &mdash; FSEJS Ain Chock, Université Hassan II de Casablanca &mdash;
    <a href="index.php" style="color:#a0aec0;">&#8592; Formulaire étudiant</a>
</footer>

</body>
</html>
