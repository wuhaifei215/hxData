namespace app\job;

use think\queue\Job;
use PhpOffice\PhpSpreadsheet\IOFactory;
use think\Db;

class ImportExcelJob
{
    protected $filePath;
    protected $adminId;

    public function __construct($filePath, $adminId)
    {
        $this->filePath = $filePath;
        $this->adminId = $adminId;
    }

    public function handle()
    {
        // 执行导入逻辑
        $this->importExcel($this->filePath, $this->adminId);
    }

    protected function importExcel($filePath, $adminId)
    {
        // 导入逻辑与之前相同
        $startTime = microtime(true);
        $memoryStart = memory_get_usage();
        $batchSize = 1000;
        $successCount = 0;
        $duplicateCount = 0;
        $emptyCount = 0;
        $errorMessages = [];
        $insertedIds = [];

        try {
            $reader = IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();

            $existingAccounts = Db::name('data')->column('account');
            $existingAccounts = array_flip($existingAccounts);

            Db::startTrans();

            for ($startRow = 2; $startRow <= $highestRow; $startRow += $batchSize) {
                $batchInsertData = [];
                for ($j = $startRow; $j < $startRow + $batchSize && $j <= $highestRow; $j++) {
                    $row = $sheet->rangeToArray('A' . $j . ':' . $sheet->getHighestColumn() . $j, null, true, false, false);
                    $row = $row[0];

                    $lineNumber = $j;
                    $account = isset($row[0]) ? trim($row[0]) : '';
                    $username = isset($row[1]) ? trim($row[1]) : '';

                    if (empty($account)) {
                        $emptyCount++;
                        $errorMsg = "第{$lineNumber}行: account为空";
                        $errorMessages[] = $errorMsg;
                        continue;
                    }

                    if (isset($existingAccounts[$account])) {
                        $duplicateCount++;
                        $errorMsg = "第{$lineNumber}行: account '{$account}' 已存在";
                        $errorMessages[] = $errorMsg;
                        continue;
                    }

                    $batchInsertData[] = [
                        'username'    => $username,
                        'account'     => $account,
                        'create_time' => time(),
                    ];

                    $existingAccounts[$account] = true;
                }

                if (!empty($batchInsertData)) {
                    $result = Db::name('data')->insertAll($batchInsertData);
                    $successCount += $result;

                    if ($result > 0) {
                        $firstId = Db::name('data')->getLastInsID() - $result + 1;
                        $insertedIds[] = [
                            'start' => $firstId,
                            'end' => $firstId + $result - 1,
                            'count' => $result
                        ];
                    }
                }

                unset($batchInsertData);
                gc_collect_cycles();
            }

            Db::commit();

            $timeUsed = round(microtime(true) - $startTime, 3);
            $memoryUsed = round((memory_get_usage() - $memoryStart) / 1024 / 1024, 2);
            $totalProcessed = $successCount + $duplicateCount + $emptyCount;

            $log_msg = date('Y-m-d H:i:s') . "--文件名：(" . basename($filePath) . ")。总处理:" . $totalProcessed . "行。成功导入: " . $successCount . "行。";
            sysoplog('数据管理导入', $log_msg);

        } catch (\Exception $e) {
            Db::rollback();
            // 记录错误日志
        }
    }
}
