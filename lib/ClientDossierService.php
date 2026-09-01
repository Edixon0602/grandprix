<?php
declare(strict_types=1);

final class ClientDossierService
{
    private string $root;

    /** @var array<string,array<string,mixed>> */
    private array $definitions = [
        'payment_pdf' => [
            'label' => 'PDF de pagos',
            'description' => 'Recibos y comprobantes de pagos realizados',
            'purpose' => 'Para revisar pagos y confirmar historial de abonos.',
            'category' => 'payments',
            'folder' => 'PAGOS_PDF',
            'icon' => 'fa-file-pdf',
        ],
        'contract' => [
            'label' => 'Contrato',
            'description' => 'Contrato de financiamiento firmado',
            'purpose' => 'Para formalizar el acuerdo y las condiciones del crédito.',
            'category' => 'documents',
            'folder' => 'DOCUMENTOS/CONTRATO',
            'icon' => 'fa-file-signature',
        ],
        'identity' => [
            'label' => 'Cédula de identidad',
            'description' => 'Copia legible de la cédula por ambos lados',
            'purpose' => 'Para verificar la identidad del cliente.',
            'category' => 'documents',
            'folder' => 'DOCUMENTOS/CEDULA_IDENTIDAD',
            'icon' => 'fa-id-card',
        ],
        'residence_letter' => [
            'label' => 'Carta de residencia',
            'description' => 'Constancia de residencia actual',
            'purpose' => 'Para comprobar la dirección de residencia.',
            'category' => 'documents',
            'folder' => 'DOCUMENTOS/CARTA_RESIDENCIA',
            'icon' => 'fa-file-lines',
        ],
        'house_front' => [
            'label' => 'Foto frente de la casa',
            'description' => 'Foto clara del frente de la vivienda',
            'purpose' => 'Para validar visualmente la residencia del cliente.',
            'category' => 'documents',
            'folder' => 'DOCUMENTOS/FOTO_FRENTE_CASA',
            'icon' => 'fa-house-chimney',
        ],
        'home_location' => [
            'label' => 'Ubicación GPS de la casa',
            'description' => 'Coordenadas de la residencia del cliente',
            'purpose' => 'Para confirmar la ubicación geográfica de la vivienda.',
            'category' => 'documents',
            'folder' => 'DOCUMENTOS/UBICACION_GPS',
            'icon' => 'fa-location-dot',
        ],
    ];

    public function __construct(private readonly PDO $pdo)
    {
        $this->root = dirname(__DIR__) . '/config/clientes';
    }

    public function ready(): bool
    {
        return $this->tableExists('gp_client_dossier_files') && $this->tableExists('gp_client_dossier_meta');
    }

    /** @return array<string,array<string,mixed>> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /** @return array<int,array<string,mixed>> keyed by account id */
    public function summaries(): array
    {
        if (!$this->ready()) return [];
        $accounts = $this->pdo->query("SELECT id FROM gp_finance_accounts WHERE record_status <> 'archived'")->fetchAll(PDO::FETCH_COLUMN);
        $result = [];
        foreach ($accounts as $rawId) {
            $id = (int)$rawId;
            if ($id < 1) continue;
            $result[$id] = $this->summary($id);
        }
        return $result;
    }

    /** @return array<string,mixed> */
    public function summary(int $accountId): array
    {
        if (!$this->ready()) {
            return [
                'accountId' => $accountId,
                'completeCount' => 0,
                'total' => count($this->definitions),
                'percent' => 0,
                'missing' => array_keys($this->definitions),
                'statuses' => [],
                'paymentPdfCount' => 0,
            ];
        }

        $stmt = $this->pdo->prepare("SELECT doc_key, COUNT(*) files_count, MAX(created_at) latest_at
                                    FROM gp_client_dossier_files
                                    WHERE account_id=? AND status='active'
                                    GROUP BY doc_key");
        $stmt->execute([$accountId]);
        $found = [];
        foreach ($stmt->fetchAll() as $row) {
            $found[(string)$row['doc_key']] = [
                'count' => (int)$row['files_count'],
                'latestAt' => $row['latest_at'] ?? null,
            ];
        }

        $meta = $this->meta($accountId);
        if ($meta && $meta['homeLat'] !== null && $meta['homeLng'] !== null) {
            $found['home_location'] = ['count' => max(1, (int)($found['home_location']['count'] ?? 0)), 'latestAt' => $meta['homeLocationUpdatedAt'] ?? null];
        }

        $statuses = [];
        $missing = [];
        foreach ($this->definitions as $key => $def) {
            $ok = (int)($found[$key]['count'] ?? 0) > 0;
            $statuses[$key] = [
                'complete' => $ok,
                'count' => (int)($found[$key]['count'] ?? 0),
                'latestAt' => $found[$key]['latestAt'] ?? null,
                'label' => $def['label'],
            ];
            if (!$ok) $missing[] = $key;
        }
        $complete = count($this->definitions) - count($missing);
        $total = count($this->definitions);
        return [
            'accountId' => $accountId,
            'completeCount' => $complete,
            'total' => $total,
            'percent' => $total > 0 ? (int)round(($complete / $total) * 100) : 0,
            'missing' => $missing,
            'statuses' => $statuses,
            'paymentPdfCount' => (int)($found['payment_pdf']['count'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    public function detail(int $accountId, bool $sync=true): array
    {
        $account = $this->account($accountId);
        if (!$account) throw new InvalidArgumentException('El cliente no existe o está archivado.');
        if (!$this->ready()) throw new RuntimeException('Ejecuta la actualización V25 antes de abrir el expediente documental.');

        $this->ensureClientRoot($accountId, (string)$account['full_name']);
        if ($sync) $this->syncAccountSources($accountId);

        $summary = $this->summary($accountId);
        $files = $this->files($accountId);
        $meta = $this->meta($accountId);
        $byKey = [];
        foreach ($files as $file) {
            $key = (string)$file['docKey'];
            $byKey[$key] ??= [];
            $byKey[$key][] = $file;
        }

        $checklist = [];
        foreach ($this->definitions as $key => $def) {
            $items = $byKey[$key] ?? [];
            $latest = $items[0] ?? null;
            $checklist[] = [
                'key' => $key,
                'label' => $def['label'],
                'description' => $def['description'],
                'purpose' => $def['purpose'],
                'category' => $def['category'],
                'icon' => $def['icon'],
                'complete' => $key === 'home_location'
                    ? ($meta && $meta['homeLat'] !== null && $meta['homeLng'] !== null)
                    : !empty($items),
                'count' => count($items),
                'latest' => $latest,
            ];
        }

        $folderKey = $this->folderKey($accountId, (string)$account['full_name']);
        $documents = array_values(array_filter($files, static fn(array $x): bool => $x['category'] === 'documents'));
        $payments = array_values(array_filter($files, static fn(array $x): bool => $x['category'] === 'payments'));
        $activity = $this->activity($files, $meta);

        return [
            'accountId' => $accountId,
            'folderKey' => $folderKey,
            'summary' => $summary,
            'checklist' => $checklist,
            'documents' => $documents,
            'paymentPdfs' => $payments,
            'location' => $meta,
            'folders' => [
                'root' => '/CLIENTES/' . $folderKey . '/',
                'documents' => [
                    'CEDULA_IDENTIDAD', 'CONTRATO', 'CARTA_RESIDENCIA', 'FOTO_FRENTE_CASA', 'UBICACION_GPS'
                ],
                'payments' => 'PAGOS_PDF',
            ],
            'storageBytes' => array_sum(array_map(static fn(array $x): int => (int)$x['size'], $files)),
            'activity' => $activity,
        ];
    }

    /** @return array<string,mixed> */
    public function upload(int $accountId, string $docKey, array $file, string $createdBy): array
    {
        $account = $this->account($accountId);
        if (!$account) throw new InvalidArgumentException('Cliente inválido.');
        if (!$this->ready()) throw new RuntimeException('Ejecuta la actualización V25 antes de adjuntar documentos.');
        if (!isset($this->definitions[$docKey]) || $docKey === 'home_location') throw new InvalidArgumentException('Tipo de documento no permitido.');

        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) throw new InvalidArgumentException('No fue posible recibir el archivo.');
        $size = (int)($file['size'] ?? 0);
        if ($size < 1 || $size > 12 * 1024 * 1024) throw new InvalidArgumentException('El archivo debe pesar máximo 12 MB.');
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) throw new InvalidArgumentException('El archivo recibido no es válido.');
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $extMap = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if (!isset($extMap[$mime])) throw new InvalidArgumentException('Solo se permiten PDF, JPG, PNG o WEBP.');
        if ($docKey === 'payment_pdf' && $mime !== 'application/pdf') throw new InvalidArgumentException('Los comprobantes de pago deben cargarse en PDF.');
        if ($docKey === 'house_front' && !str_starts_with($mime, 'image/')) throw new InvalidArgumentException('La foto del frente de la casa debe ser una imagen.');

        $root = $this->ensureClientRoot($accountId, (string)$account['full_name']);
        $def = $this->definitions[$docKey];
        $dir = $root . '/' . $def['folder'];
        $this->ensureDir($dir);
        $original = mb_substr(trim((string)($file['name'] ?? 'archivo')), 0, 255);
        $nameBase = $this->slug(pathinfo($original, PATHINFO_FILENAME));
        if ($nameBase === '') $nameBase = $this->slug((string)$def['label']);
        $storedName = date('Ymd-His') . '-' . $nameBase . '-' . substr(bin2hex(random_bytes(5)), 0, 10) . '.' . $extMap[$mime];
        $dest = $dir . '/' . $storedName;
        if (!move_uploaded_file($tmp, $dest)) throw new RuntimeException('No fue posible almacenar el documento en la carpeta del cliente.');
        @chmod($dest, 0640);

        $relative = $this->relativePath($dest);
        $stmt = $this->pdo->prepare("INSERT INTO gp_client_dossier_files
            (account_id,doc_key,category,label,original_name,stored_path,mime_type,file_size,source_type,source_id,status,created_by)
            VALUES (?,?,?,?,?,?,?,?, 'manual',NULL,'active',?)");
        $stmt->execute([$accountId,$docKey,$def['category'],$def['label'],$original,$relative,$mime,$size,$createdBy]);
        $id = (int)$this->pdo->lastInsertId();
        return $this->fileRow($id) ?? [];
    }

    /** @return array<string,mixed> */
    public function saveLocation(int $accountId, float $lat, float $lng, string $address, string $notes, string $createdBy): array
    {
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) throw new InvalidArgumentException('Las coordenadas GPS no son válidas.');
        $account = $this->account($accountId);
        if (!$account) throw new InvalidArgumentException('Cliente inválido.');
        if (!$this->ready()) throw new RuntimeException('Ejecuta la actualización V25 antes de registrar la ubicación.');
        $this->ensureClientRoot($accountId, (string)$account['full_name']);
        $this->writeLocation($accountId, $lat, $lng, $address, $notes, $createdBy, 'manual');
        return $this->meta($accountId) ?? [];
    }

    /** @return array<string,mixed>|null */
    public function downloadable(int $fileId): ?array
    {
        if (!$this->ready()) return null;
        $row = $this->fileRow($fileId);
        if (!$row || $row['status'] !== 'active') return null;
        $path = $this->root . '/' . ltrim((string)$row['storedPath'], '/');
        $base = realpath($this->root);
        $real = realpath($path);
        if ($base === false || $real === false || !is_file($real)) return null;
        $prefix = rtrim(str_replace('\\','/',$base),'/') . '/';
        $normalized = str_replace('\\','/',$real);
        if (!str_starts_with($normalized, $prefix)) return null;
        $row['path'] = $real;
        return $row;
    }

    /** Resolve el expediente financiero que pertenece a un usuario Mi GRANDPRIX. */
    public function accountIdForPortalCustomer(int $customerId): int
    {
        if ($customerId < 1 || !$this->tableExists('gp_customers')) return 0;
        $financeAccountId = 0;
        $identity = '';
        try {
            if ($this->columnExists('gp_customers','finance_account_id')) {
                $q = $this->pdo->prepare("SELECT finance_account_id,identity_document FROM gp_customers WHERE id=? AND status<>'archived' LIMIT 1");
                $q->execute([$customerId]);
                $r = $q->fetch();
                if ($r) {
                    $financeAccountId = (int)($r['finance_account_id'] ?? 0);
                    $identity = trim((string)($r['identity_document'] ?? ''));
                }
            } else {
                $q = $this->pdo->prepare("SELECT identity_document FROM gp_customers WHERE id=? AND status<>'archived' LIMIT 1");
                $q->execute([$customerId]);
                $identity = trim((string)($q->fetchColumn() ?: ''));
            }
        } catch (Throwable) {
            return 0;
        }
        if ($financeAccountId > 0 && $this->account($financeAccountId)) return $financeAccountId;

        if ($this->tableExists('gp_contracts') && $this->tableExists('gp_finance_accounts')) {
            try {
                $q = $this->pdo->prepare("SELECT contract_number FROM gp_contracts WHERE customer_id=? AND status IN ('active','completed') ORDER BY status='active' DESC,id DESC LIMIT 1");
                $q->execute([$customerId]);
                $contract = trim((string)($q->fetchColumn() ?: ''));
                if ($contract !== '') {
                    $a = $this->pdo->prepare("SELECT id FROM gp_finance_accounts WHERE contract_number=? AND record_status<>'archived' ORDER BY id DESC LIMIT 1");
                    $a->execute([$contract]);
                    $id = (int)($a->fetchColumn() ?: 0);
                    if ($id > 0) return $id;
                }
            } catch (Throwable) {}
        }

        if ($identity !== '' && $this->tableExists('gp_finance_accounts')) {
            $digits = preg_replace('/\D+/', '', $identity) ?? '';
            if ($digits !== '') {
                try {
                    $sql = "SELECT id FROM gp_finance_accounts
                            WHERE record_status<>'archived'
                              AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(UPPER(COALESCE(identity_document,'')),'.',''),'-',''),' ',''),'V',''),'E','')=?
                            ORDER BY id DESC LIMIT 1";
                    $q = $this->pdo->prepare($sql);
                    $q->execute([$digits]);
                    $id = (int)($q->fetchColumn() ?: 0);
                    if ($id > 0) return $id;
                } catch (Throwable) {}
            }
        }
        return 0;
    }

    /** @return array<string,mixed> */
    public function portalDetail(int $customerId): array
    {
        $accountId = $this->accountIdForPortalCustomer($customerId);
        if ($accountId < 1) throw new RuntimeException('Tu usuario todavía no está vinculado a un expediente financiero.');
        $detail = $this->detail($accountId, true);
        $cleanFile = static function(?array $file): ?array {
            if (!$file) return null;
            unset($file['storedPath']);
            return $file;
        };
        foreach ($detail['checklist'] as &$item) {
            $item['latest'] = $cleanFile(is_array($item['latest'] ?? null) ? $item['latest'] : null);
        }
        unset($item);
        $detail['documents'] = array_values(array_map($cleanFile, $detail['documents'] ?? []));
        $detail['paymentPdfs'] = array_values(array_map($cleanFile, $detail['paymentPdfs'] ?? []));
        // La ruta mostrada es virtual; la carpeta física nunca se expone al navegador.
        $detail['folders']['root'] = '/CLIENTES/' . (string)($detail['folderKey'] ?? '') . '/';
        return $detail;
    }

    /** @return array<string,mixed>|null */
    public function downloadableForPortalCustomer(int $customerId, int $fileId): ?array
    {
        $accountId = $this->accountIdForPortalCustomer($customerId);
        if ($accountId < 1) return null;
        $file = $this->downloadable($fileId);
        if (!$file || (int)($file['accountId'] ?? 0) !== $accountId) return null;
        return $file;
    }

    /** @return array<string,mixed> */
    public function syncAccountSources(int $accountId): array
    {
        $account = $this->account($accountId);
        if (!$account) return ['accountId'=>$accountId,'imported'=>0,'payments'=>0,'location'=>false];
        $this->ensureClientRoot($accountId, (string)$account['full_name']);
        $imported = 0;
        $imported += $this->syncCustomerDocuments($accountId, $account);
        $imported += $this->syncApplicationData($accountId);
        $payments = $this->syncPaymentPdfs($accountId);
        $meta = $this->meta($accountId);
        return ['accountId'=>$accountId,'imported'=>$imported,'payments'=>$payments,'location'=>($meta && $meta['homeLat'] !== null && $meta['homeLng'] !== null)];
    }

    /** @return array<string,mixed> */
    public function syncAll(): array
    {
        if (!$this->ready()) throw new RuntimeException('Las tablas de expedientes todavía no están disponibles.');
        $ids = $this->pdo->query("SELECT id FROM gp_finance_accounts WHERE record_status <> 'archived' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $result = ['accounts'=>0,'filesImported'=>0,'paymentPdfs'=>0,'locations'=>0,'errors'=>[]];
        foreach ($ids as $rawId) {
            $id = (int)$rawId;
            try {
                $r = $this->syncAccountSources($id);
                $result['accounts']++;
                $result['filesImported'] += (int)$r['imported'];
                $result['paymentPdfs'] += (int)$r['payments'];
                if ($r['location']) $result['locations']++;
            } catch (Throwable $e) {
                $result['errors'][] = 'Cliente #'.$id.': '.$e->getMessage();
            }
        }
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private function files(int $accountId): array
    {
        $q = $this->pdo->prepare("SELECT * FROM gp_client_dossier_files WHERE account_id=? AND status='active' ORDER BY created_at DESC,id DESC");
        $q->execute([$accountId]);
        return array_map([$this,'presentFile'], $q->fetchAll());
    }

    /** @return array<string,mixed>|null */
    private function fileRow(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM gp_client_dossier_files WHERE id=? LIMIT 1');
        $q->execute([$id]);
        $row = $q->fetch();
        return $row ? $this->presentFile($row) : null;
    }

    /** @return array<string,mixed> */
    private function presentFile(array $r): array
    {
        return [
            'id'=>(int)$r['id'],
            'accountId'=>(int)$r['account_id'],
            'docKey'=>(string)$r['doc_key'],
            'category'=>(string)$r['category'],
            'label'=>(string)$r['label'],
            'originalName'=>(string)$r['original_name'],
            'storedPath'=>(string)$r['stored_path'],
            'mime'=>(string)$r['mime_type'],
            'size'=>(int)$r['file_size'],
            'sourceType'=>(string)$r['source_type'],
            'sourceId'=>$r['source_id']===null?null:(int)$r['source_id'],
            'status'=>(string)$r['status'],
            'createdBy'=>$r['created_by']??null,
            'createdAt'=>$r['created_at']??null,
        ];
    }

    /** @return array<string,mixed>|null */
    private function meta(int $accountId): ?array
    {
        if (!$this->tableExists('gp_client_dossier_meta')) return null;
        $q=$this->pdo->prepare('SELECT * FROM gp_client_dossier_meta WHERE account_id=? LIMIT 1');
        $q->execute([$accountId]);
        $r=$q->fetch();
        if(!$r)return null;
        return [
            'accountId'=>(int)$r['account_id'],
            'folderKey'=>(string)$r['folder_key'],
            'homeLat'=>$r['home_lat']===null?null:(float)$r['home_lat'],
            'homeLng'=>$r['home_lng']===null?null:(float)$r['home_lng'],
            'homeAddress'=>$r['home_address']??null,
            'homeNotes'=>$r['home_notes']??null,
            'homeLocationUpdatedAt'=>$r['home_location_updated_at']??null,
            'updatedBy'=>$r['updated_by']??null,
        ];
    }

    /** @param array<int,array<string,mixed>> $files */
    private function activity(array $files, ?array $meta): array
    {
        $items=[];
        foreach(array_slice($files,0,30) as $f){
            $items[]=[
                'type'=>$f['docKey']==='payment_pdf'?'payment_pdf':'document',
                'label'=>($f['docKey']==='payment_pdf'?'PDF de pago disponible: ':'Documento cargado: ').$f['label'],
                'date'=>$f['createdAt'],
                'by'=>$f['createdBy']?:'Sistema',
                'fileId'=>$f['id'],
            ];
        }
        if($meta && $meta['homeLocationUpdatedAt'])$items[]=['type'=>'location','label'=>'Ubicación GPS de la vivienda actualizada','date'=>$meta['homeLocationUpdatedAt'],'by'=>$meta['updatedBy']?:'Sistema','fileId'=>null];
        usort($items, static fn(array $a,array $b):int => strcmp((string)$b['date'],(string)$a['date']));
        return array_slice($items,0,30);
    }

    private function ensureClientRoot(int $accountId, string $fullName): string
    {
        $this->ensureDir($this->root);
        $folderKey = $this->folderKey($accountId, $fullName);
        $root = $this->root . '/' . $folderKey;
        $folders = [
            $root,
            $root.'/DOCUMENTOS',
            $root.'/DOCUMENTOS/CEDULA_IDENTIDAD',
            $root.'/DOCUMENTOS/CONTRATO',
            $root.'/DOCUMENTOS/CARTA_RESIDENCIA',
            $root.'/DOCUMENTOS/FOTO_FRENTE_CASA',
            $root.'/DOCUMENTOS/UBICACION_GPS',
            $root.'/DOCUMENTOS/OTROS',
            $root.'/PAGOS_PDF',
        ];
        foreach($folders as $dir)$this->ensureDir($dir);
        return $root;
    }

    private function folderKey(int $accountId, string $fullName): string
    {
        $account = $this->account($accountId);
        $identity = trim((string)($account['identity_document'] ?? ''));
        $desired = $this->identityFolderKey($identity, $accountId);
        $existing = '';

        if ($this->tableExists('gp_client_dossier_meta')) {
            $q = $this->pdo->prepare('SELECT folder_key FROM gp_client_dossier_meta WHERE account_id=? LIMIT 1');
            $q->execute([$accountId]);
            $existing = trim((string)($q->fetchColumn() ?: ''));
        }

        if ($existing !== '' && $existing !== $desired) {
            $this->migrateFolderToIdentity($accountId, $existing, $desired);
        }

        if ($this->tableExists('gp_client_dossier_meta')) {
            try {
                $this->pdo->prepare("INSERT INTO gp_client_dossier_meta (account_id,folder_key) VALUES (?,?) ON DUPLICATE KEY UPDATE folder_key=VALUES(folder_key)")
                    ->execute([$accountId, $desired]);
            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000') {
                    throw new RuntimeException('La cédula '.$desired.' ya está vinculada a otro expediente. Revisa clientes duplicados antes de continuar.');
                }
                throw $e;
            }
        }
        return $desired;
    }

    private function identityFolderKey(string $identity, int $accountId): string
    {
        $identity = trim($identity);
        if ($identity !== '') {
            $digits = preg_replace('/\D+/', '', $identity) ?? '';
            if ($digits !== '') return $digits;
            $safe = $this->slug($identity);
            if ($safe !== '') return $safe;
        }
        return 'PENDIENTE_CEDULA_' . $accountId;
    }

    private function migrateFolderToIdentity(int $accountId, string $oldKey, string $newKey): void
    {
        if ($oldKey === '' || $newKey === '' || $oldKey === $newKey) return;
        $this->ensureDir($this->root);
        $target = $this->root . '/' . $newKey;
        $legacyRoot = dirname(__DIR__) . '/config/client-dossiers';
        $sources = [
            $this->root . '/' . $oldKey,
            $legacyRoot . '/' . $oldKey,
        ];

        foreach ($sources as $source) {
            if (!is_dir($source) || rtrim($source, '/') === rtrim($target, '/')) continue;
            if (!is_dir($target)) {
                if (@rename($source, $target)) continue;
                $this->ensureDir($target);
            }
            $this->copyDirectoryTree($source, $target);
        }

        if ($this->tableExists('gp_client_dossier_files')) {
            $q = $this->pdo->prepare('SELECT id, stored_path FROM gp_client_dossier_files WHERE account_id=?');
            $q->execute([$accountId]);
            $update = $this->pdo->prepare('UPDATE gp_client_dossier_files SET stored_path=? WHERE id=?');
            foreach ($q->fetchAll() as $row) {
                $path = str_replace('\\', '/', (string)$row['stored_path']);
                $prefix = $oldKey . '/';
                if (str_starts_with($path, $prefix)) {
                    $update->execute([$newKey . '/' . substr($path, strlen($prefix)), (int)$row['id']]);
                }
            }
        }
    }

    private function copyDirectoryTree(string $source, string $target): void
    {
        $this->ensureDir($target);
        $items = scandir($source);
        if ($items === false) throw new RuntimeException('No fue posible leer una carpeta anterior del expediente.');
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $src = $source . '/' . $item;
            $dst = $target . '/' . $item;
            if (is_dir($src)) {
                $this->copyDirectoryTree($src, $dst);
            } elseif (is_file($src) && !is_file($dst)) {
                if (!@copy($src, $dst)) throw new RuntimeException('No fue posible migrar un archivo del expediente a la carpeta por cédula.');
                @chmod($dst, 0640);
            }
        }
    }

    private function syncCustomerDocuments(int $accountId, array $account): int
    {
        if(!$this->tableExists('gp_customer_documents'))return 0;
        $customerId=0;
        if(!empty($account['contract_number']) && $this->tableExists('gp_contracts')){
            $q=$this->pdo->prepare('SELECT customer_id FROM gp_contracts WHERE contract_number=? ORDER BY id DESC LIMIT 1');$q->execute([(string)$account['contract_number']]);$customerId=(int)($q->fetchColumn()?:0);
        }
        if(!$customerId && !empty($account['identity_document']) && $this->tableExists('gp_customers')){
            $q=$this->pdo->prepare('SELECT id FROM gp_customers WHERE identity_document=? LIMIT 1');$q->execute([(string)$account['identity_document']]);$customerId=(int)($q->fetchColumn()?:0);
        }
        if(!$customerId)return 0;
        $q=$this->pdo->prepare("SELECT * FROM gp_customer_documents WHERE customer_id=? AND status='active' ORDER BY id");$q->execute([$customerId]);
        $root=dirname(__DIR__).'/config/customer-documents';$count=0;
        foreach($q->fetchAll() as $r){
            $sourceId=(int)$r['id'];
            if($this->sourceExists($accountId,'customer_document',$sourceId))continue;
            $source=$root.'/'.ltrim((string)$r['stored_path'],'/');if(!is_file($source))continue;
            $label=(string)($r['label']??'');$type=(string)($r['document_type']??'other');$docKey=$this->mapLegacyType($type,$label);
            $count += $this->copySourceFile($accountId,$docKey,$source,(string)($r['original_name']??basename($source)),(string)($r['mime_type']??'application/octet-stream'),'customer_document',$sourceId,(string)($r['created_by']??'Sistema'))?1:0;
        }
        return $count;
    }

    private function syncApplicationData(int $accountId): int
    {
        if(!$this->tableExists('gp_finance_applications') || !$this->columnExists('gp_finance_applications','finance_account_id'))return 0;
        $q=$this->pdo->prepare('SELECT * FROM gp_finance_applications WHERE finance_account_id=? ORDER BY id DESC LIMIT 1');$q->execute([$accountId]);$app=$q->fetch();if(!$app)return 0;
        $appId=(int)$app['id'];$count=0;$root=dirname(__DIR__).'/config/application-files';
        if($this->tableExists('gp_finance_application_documents')){
            $d=$this->pdo->prepare('SELECT * FROM gp_finance_application_documents WHERE application_id=? ORDER BY id');$d->execute([$appId]);
            foreach($d->fetchAll() as $r){
                $sourceId=(int)$r['id'];if($this->sourceExists($accountId,'application_document',$sourceId))continue;
                $source=$root.'/'.ltrim((string)$r['stored_path'],'/');if(!is_file($source))continue;
                $docKey=$this->mapApplicationType((string)$r['doc_type']);
                $count += $this->copySourceFile($accountId,$docKey,$source,(string)$r['original_name'],(string)$r['mime_type'],'application_document',$sourceId,'Solicitud web')?1:0;
            }
        }
        if(isset($app['latitude'],$app['longitude']) && $app['latitude']!==null && $app['longitude']!==null){
            $meta=$this->meta($accountId);
            if(!$meta || $meta['homeLat']===null || $meta['homeLng']===null){
                $this->writeLocation($accountId,(float)$app['latitude'],(float)$app['longitude'],(string)($app['address']??''),(string)($app['visit_notes']??''),'Solicitud web','application');
            }
        }
        return $count;
    }

    private function syncPaymentPdfs(int $accountId): int
    {
        if(!$this->tableExists('gp_finance_receipts'))return 0;
        $receiptServiceFile=__DIR__.'/PaymentReceiptService.php';$rendererFile=__DIR__.'/ReceiptPdfRenderer.php';
        if(!is_file($receiptServiceFile)||!is_file($rendererFile))return 0;
        require_once $receiptServiceFile;require_once $rendererFile;
        if(!class_exists('PaymentReceiptService')||!class_exists('ReceiptPdfRenderer'))return 0;
        $q=$this->pdo->prepare('SELECT id FROM gp_finance_receipts WHERE account_id=? ORDER BY paid_at,id');$q->execute([$accountId]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);if(!$ids)return 0;
        $service=new PaymentReceiptService($this->pdo);$account=$this->account($accountId);if(!$account)return 0;$root=$this->ensureClientRoot($accountId,(string)$account['full_name']).'/PAGOS_PDF';$count=0;
        foreach($ids as $rawId){
            $id=(int)$rawId;if($this->sourceExists($accountId,'receipt',$id))continue;$receipt=$service->receipt($id);if(!$receipt)continue;
            $number=$this->slug((string)($receipt['receiptNumber']??('RECIBO-'.$id)));if($number==='')$number='RECIBO-'.$id;
            $name=$number.'.pdf';$dest=$root.'/'.$name;
            try{$bytes=ReceiptPdfRenderer::bytes($receipt);if(file_put_contents($dest,$bytes)===false)continue;@chmod($dest,0640);}catch(Throwable){continue;}
            $this->insertCopied($accountId,'payment_pdf','payments','PDF de pagos',$name,$dest,'application/pdf',filesize($dest)?:strlen($bytes),'receipt',$id,(string)($receipt['createdBy']??'Sistema'));
            $count++;
        }
        return $count;
    }

    private function writeLocation(int $accountId,float $lat,float $lng,string $address,string $notes,string $createdBy,string $sourceType): void
    {
        $account=$this->account($accountId);if(!$account)throw new InvalidArgumentException('Cliente inválido.');
        $folderKey=$this->folderKey($accountId,(string)$account['full_name']);
        $this->pdo->prepare("INSERT INTO gp_client_dossier_meta (account_id,folder_key,home_lat,home_lng,home_address,home_notes,home_location_updated_at,updated_by)
            VALUES (?,?,?,?,?,?,NOW(),?) ON DUPLICATE KEY UPDATE home_lat=VALUES(home_lat),home_lng=VALUES(home_lng),home_address=VALUES(home_address),home_notes=VALUES(home_notes),home_location_updated_at=NOW(),updated_by=VALUES(updated_by)")
            ->execute([$accountId,$folderKey,$lat,$lng,mb_substr(trim($address),0,300)?:null,mb_substr(trim($notes),0,1000)?:null,$createdBy]);
        $root=$this->ensureClientRoot($accountId,(string)$account['full_name']);$dir=$root.'/DOCUMENTOS/UBICACION_GPS';$this->ensureDir($dir);
        $data=['accountId'=>$accountId,'client'=>$account['full_name'],'latitude'=>$lat,'longitude'=>$lng,'address'=>$address,'notes'=>$notes,'updatedAt'=>date('c'),'updatedBy'=>$createdBy];
        $name='ubicacion-gps-'.date('Ymd-His').'.json';$dest=$dir.'/'.$name;file_put_contents($dest,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));@chmod($dest,0640);
        $this->pdo->prepare("UPDATE gp_client_dossier_files SET status='archived' WHERE account_id=? AND doc_key='home_location' AND source_type='location' AND status='active'")->execute([$accountId]);
        $this->insertCopied($accountId,'home_location','documents','Ubicación GPS de la casa',$name,$dest,'application/json',filesize($dest)?:0,'location',null,$createdBy);
    }

    private function copySourceFile(int $accountId,string $docKey,string $source,string $original,string $mime,string $sourceType,int $sourceId,string $createdBy): bool
    {
        $account=$this->account($accountId);if(!$account)return false;$root=$this->ensureClientRoot($accountId,(string)$account['full_name']);$def=$this->definitions[$docKey]??['folder'=>'DOCUMENTOS/OTROS','category'=>'documents','label'=>'Documento'];$dir=$root.'/'.$def['folder'];$this->ensureDir($dir);
        $ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));if($ext==='')$ext=strtolower(pathinfo($source,PATHINFO_EXTENSION));if($ext==='')$ext='bin';
        $base=$this->slug(pathinfo($original,PATHINFO_FILENAME));if($base==='')$base='archivo-'.$sourceId;$name=$sourceType.'-'.$sourceId.'-'.$base.'.'.$ext;$dest=$dir.'/'.$name;
        if(!is_file($dest) && !@copy($source,$dest))return false;@chmod($dest,0640);
        $this->insertCopied($accountId,$docKey,(string)$def['category'],(string)$def['label'],$original,$dest,$mime,filesize($dest)?:0,$sourceType,$sourceId,$createdBy);
        return true;
    }

    private function insertCopied(int $accountId,string $docKey,string $category,string $label,string $original,string $dest,string $mime,int $size,string $sourceType,?int $sourceId,string $createdBy): void
    {
        $stmt=$this->pdo->prepare("INSERT INTO gp_client_dossier_files (account_id,doc_key,category,label,original_name,stored_path,mime_type,file_size,source_type,source_id,status,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?, 'active',?)");
        $stmt->execute([$accountId,$docKey,$category,$label,mb_substr($original,0,255),$this->relativePath($dest),$mime,$size,$sourceType,$sourceId,$createdBy]);
    }

    private function sourceExists(int $accountId,string $type,int $sourceId): bool
    {
        $q=$this->pdo->prepare("SELECT id FROM gp_client_dossier_files WHERE account_id=? AND source_type=? AND source_id=? LIMIT 1");$q->execute([$accountId,$type,$sourceId]);return (bool)$q->fetchColumn();
    }

    private function mapLegacyType(string $type,string $label): string
    {
        $text=mb_strtolower($type.' '.$label);
        if(str_contains($text,'identity')||str_contains($text,'cédula')||str_contains($text,'cedula'))return'identity';
        if(str_contains($text,'contrato'))return'contract';
        if(str_contains($text,'residencia'))return'residence_letter';
        if(str_contains($text,'fachada')||str_contains($text,'frente')||str_contains($text,'casa'))return'house_front';
        if($type==='identity')return'identity';
        if($type==='contract')return'contract';
        return'other';
    }

    private function mapApplicationType(string $type): string
    {
        $type=mb_strtolower(trim($type));
        if(in_array($type,['identity','identity_card','identity_front','identity_back'],true))return'identity';
        if(in_array($type,['facade','facade_photo'],true))return'house_front';
        return'other';
    }

    /** @return array<string,mixed>|null */
    private function account(int $id): ?array
    {
        $q=$this->pdo->prepare("SELECT id,full_name,identity_document,contract_number,record_status FROM gp_finance_accounts WHERE id=? AND record_status<>'archived' LIMIT 1");$q->execute([$id]);$r=$q->fetch();return$r?:null;
    }

    private function relativePath(string $absolute): string
    {
        $root=rtrim(str_replace('\\','/',$this->root),'/').'/';$path=str_replace('\\','/',$absolute);return str_starts_with($path,$root)?substr($path,strlen($root)):$path;
    }

    private function ensureDir(string $dir): void
    {
        if(!is_dir($dir) && !mkdir($dir,0750,true) && !is_dir($dir))throw new RuntimeException('No fue posible crear la carpeta privada del expediente.');
    }

    private function slug(string $value): string
    {
        $value=trim($value);if($value==='')return'';$ascii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value);if($ascii!==false)$value=$ascii;$value=mb_strtoupper($value);$value=preg_replace('/[^A-Z0-9]+/','_',$value)??'';return trim($value,'_');
    }

    private function tableExists(string $table): bool
    {
        try{$q=$this->pdo->query('SHOW TABLES LIKE '.$this->pdo->quote($table));return(bool)$q->fetchColumn();}catch(Throwable){return false;}
    }

    private function columnExists(string $table,string $column): bool
    {
        try{$q=$this->pdo->query('SHOW COLUMNS FROM `'.str_replace('`','',$table).'` LIKE '.$this->pdo->quote($column));return(bool)$q->fetch();}catch(Throwable){return false;}
    }
}
