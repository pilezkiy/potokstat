<?
require 'vendor/autoload.php';
use \PhpOffice\PhpSpreadsheet\Spreadsheet;
use \PhpOffice\PhpSpreadsheet\Reader\Csv;
use \PhpOffice\PhpSpreadsheet\Reader\Xlsx;
session_start();
ini_set('display_errors', 0);
$sessCode = &$_SESSION['sessCode'];
if(empty($sessCode)) $_SESSION['sessCode'] = substr(md5(time().'//'.$_SERVER['REMOTE_ADDR'].'//'.$_SERVER['HTTP_USER_AGENT']), 0, 6);
$tmpDir = __DIR__.'/tmp/'.$sessCode;
mkdir($tmpDir,0777,true);
?>
<html>
<head>
<link rel="stylesheet" href="style.css" type="text/css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.7.2/Chart.bundle.min.js"></script>
<script type="text/javascript" src="script.js"></script>
</head>
<body>
<form action="" method="post" enctype="multipart/form-data" class="ptk-form">
	<div>Выберите файл выписки (zip, xlsx, csv): <input type="file" name="statFile"></div>
	<button>Отправить</button>
</form>
<?
$statData = array();
if($_FILES['statFile'])
{
	$fileType = $_FILES['statFile']['type'];
	$fileName = $_FILES['statFile']['name'];
	$filePathAbs = $_FILES['statFile']['tmp_name'];
	if(strpos($fileType, '/zip') !== false)
	{
		if(class_exists('\ZipArchive'))
		{
			$zip = new ZipArchive;
			if($zip->open($filePathAbs) === true)
			{
				$zip->extractTo($tmpDir);
				if ($handle = opendir($tmpDir))
				{
					while (false !== ($entry = readdir($handle)))
					{
						if ($entry == "." || $entry == "..") continue;
						$tmpFilePath = $tmpDir.'/'.$entry;
						if(!file_exists($tmpFilePath)) continue;
						if(filesize($tmpFilePath) <= 0) continue;
						if(!is_file($tmpFilePath)) continue;
						if(preg_match('#\.(xlsx|csv)$#', $entry))
						{
							$pathinfo = pathinfo($tmpFilePath);
							$fileType = mime_content_type($tmpFilePath);
							$fileName = $pathinfo['basename'];
							$filePathAbs = $tmpFilePath;
						}
					}
					closedir($handle);
				}
			}
		}
	}
	
	$sheetData = false;
	if (preg_match('#\.xlsx$#uis',$fileName) && ($handle = fopen($filePathAbs, "r")) !== FALSE)
	{
		$reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
		$spreadsheet = $reader->load($filePathAbs);
		$sheetData = $spreadsheet->getActiveSheet()->toArray();
	}

	if (preg_match('#\.xls$#uis',$fileName) && ($handle = fopen($filePathAbs, "r")) !== FALSE)
	{
		$reader = new \PhpOffice\PhpSpreadsheet\Reader\Xls();
		$spreadsheet = $reader->load($filePathAbs);
		$sheetData = $spreadsheet->getActiveSheet()->toArray();
	}

	if (preg_match('#\.csv$#uis',$fileName) && ($handle = fopen($filePathAbs, "r")) !== FALSE)
	{
		$reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
		$spreadsheet = $reader->load($filePathAbs);
		$sheetData = $spreadsheet->getActiveSheet()->toArray();
	}
	
	
	if(is_array($sheetData) && count($sheetData) > 0)
	{
		$firstRow = array_shift($sheetData);
		foreach ($sheetData as $row)
		{
			$dataRow = array();
			foreach ($row as $k => $v)
			{
				switch (true)
				{
					case preg_match('#Дата#uis', $firstRow[$k]):
						$dataRow['date'] = date('Y-m-d',strtotime($v));
						break;
					case preg_match('#Тип\s+операции#uis', $firstRow[$k]):
						$dataRow['type'] = $v;
						break;
					case preg_match('#Наименование\s+заемщика#uis', $firstRow[$k]):
						$dataRow['debtor'] = $v;
						break;
					case preg_match('#Номер\s+договора#uis', $firstRow[$k]):
						$dataRow['number'] = $v;
						break;
					case preg_match('#Сумма#uis', $firstRow[$k]):
						$v = str_replace(',', '.', $v);
						$v = doubleval($v);
						$dataRow['sum'] = $v;
						break;
				}
			}
			
			if($dataRow && $dataRow['type'] && $dataRow['number'] && $dataRow['sum'] && $dataRow['date'] && $dataRow['debtor'])
			{
				$statData[$dataRow['number']]['number'] = $dataRow['number'];
				$statData[$dataRow['number']]['debtor'] = $dataRow['debtor'];
				if(preg_match('#инвест.*#uis', $dataRow['type']))
				{
					$statData[$dataRow['number']]['investDate'] = $dataRow['date'];
					$statData[$dataRow['number']]['investSum'] += $dataRow['sum'];
				}
				else
				{
					$statData[$dataRow['number']]['operations'][$dataRow['type']][$dataRow['date']] += $dataRow['sum'];
				}
			}
		}
		
		
	}
}


uasort($statData, function($a,$b) {
	return strtotime($a['investDate']) - strtotime($b['investDate']);
});

foreach ($statData as &$a)
{
	$a["payedSum"] = 0;
	$a["lastPayDate"] = false;
	$a["payedPercent"] = 0;
	$a["lastPercentDate"] = false;
	$a["payedFine"] = 0;
	$a["lastFineDate"] = false;
	if($a['operations'])
	{
		foreach ($a['operations'] as $k => &$ops)
		{
			ksort($ops);
			$keys = array_keys($ops);
			$lastDate = end($keys);
			if(preg_match('#процент#uis', $k))
			{
				$a['payedPercent'] = array_sum($ops);
				$a['lastPercentDate'] = $lastDate;
			}
			elseif(preg_match('#выплата\s+ОД#uis', $k))
			{
				$a['payedSum'] = array_sum($ops);
				$a['lastPayDate'] = $lastDate;
			}
			elseif(preg_match('#пени#uis', $k))
			{
				$a['payedFine'] = array_sum($ops);
				$a['lastFineDate'] = $lastDate;
			}
		}
		unset($ops);
	}
	
	$a['allPayed'] = $a['payedSum'] + $a['payedPercent'] + $a['payedFine'];
	$a['lastDate'] = $a['investDate'];
	if(strtotime($a['lastDate']) < strtotime($a["lastPayDate"])) $a['lastDate'] = $a["lastPayDate"];
	if(strtotime($a['lastDate']) < strtotime($a["lastPercentDate"])) $a['lastDate'] = $a["lastPercentDate"];
	if(strtotime($a['lastDate']) < strtotime($a["lastFineDate"])) $a['lastDate'] = $a["lastFineDate"];
	
	if($a['investSum'] > $a['allPayed'] && ((time() - strtotime($a['lastDate'])) > 86400*28)) $a['failed'] = true;
	$a['debt'] = doubleval($a['investSum']) - doubleval($a['payedSum']);
	if($a['debt'] < 0) $a['debt'] = 0;
	$a['debt'] = round($a['debt'],2);
	if($a['debt'] > 1 && ((time() - strtotime($a['lastDate'])) > 86400*28)) $a['failed'] = true;
	if($ts = strtotime($a['investDate']))
	{
		$investDate = new \DateTime(date('Y-m-d',$ts));
		$nowDate = new \DateTime(date('Y-m-d'));
		$interval = $nowDate->diff($investDate);
		$month = $interval->format('%m');
		$years = $interval->format('%y');
		$years = intval($years);
		$a['month'] = $month = intval($month) + $years * 12;
		$a['isCurrent'] = true;
		if($month > 6) $a['isCurrent'] = false;
	}
	
	if($a['debt'] < 1) $a['isCurrent'] = false;// займ выплачен
	$a['success'] = false;
	if($a['debt'] < 1 && $a['failed'] !== true)
	{
		$a['success'] = true;
	}
	
}
unset($a);


if($statData)
{
	?>
	<table class="ptk-loans">
		<thead>
			<tr>
				<th>Договор</th>
				<th>Дата займа</th>
				<th>Сумма займа</th>
				<th>Выплата ОД</th>
				<th>Проценты</th>
				<th>Пени</th>
				<th>Долг</th>
				<th>Доход</th>
			</tr>
		</thead>
		<tbody>
			<?
			foreach ($statData as $loan)
			{
				$hashNumber = md5($loan['number']);
				?>
				<tr class="ptk-loan <?=$loan['failed']?'ptk-loan_failed':''?> <?=$loan['success']?'ptk-loan_success':''?>" data-number-hash="<?=$hashNumber?>">
					<td>
						<div class="ptk-loan__number"><?=$loan['number']?></div>
						<div class="ptk-loan__debtor"><?=$loan['debtor']?></div>
					</td>
					<td class="ptk-loan__date"><?=$loan['investDate']?></td>
					<td class="ptk-loan__sum"><?=$loan['investSum']?></td>
					<td class="ptk-loan__payedsum">
						<div><?=$loan['payedSum']?$loan['payedSum']:'-'?></div>
						<small><?=$loan['lastPayDate']?></small>
					</td>
					<td class="ptk-loan__percent">
						<div><?=$loan['payedPercent']?$loan['payedPercent']:'-'?></div>
						<small><?=$loan['lastPercentDate']?></small>
					</td>
					<td class="ptk-loan__fine">
						<div><?=$loan['payedFine']?$loan['payedFine']:'-'?></div>
						<small><?=$loan['lastFineDate']?></small>
					</td>
					<td>
						<?=$loan['debt']?>&#8381;
					</td>
					<td class="ptk-loan__profit">
						<?
						if($loan['isCurrent'] === false)
						{
							?>
							<div>
							<?
							$investSum = doubleval($loan['investSum']);
							$profitSum = doubleval($loan['allPayed']) - $investSum;
							echo $profitSum.'&nbsp;&#8381;';
							?>
							</div>
							<div><small>
							<?
							$profitPercent = ($investSum>0?round(($profitSum/$investSum)*100,3):0);
							echo $profitPercent.'%';
							$cntDays = (strtotime($loan['lastDate']) - strtotime($loan['investDate']))/86400;
							echo ' за '.$cntDays.' дн.'
							?></small></div>
							<div>
							<small>
							<?
							if($profitPercent <> 0 && $cntDays > 0)
							{
								$pd = ($profitPercent/$cntDays) * 365;
								echo round($pd,2).'% годовых';
							}
							?>
							</small>
							</div>
							<?
						}
						?>
					</td>
				</tr>
				<tr class="ptk-loan__operations" data-number-hash="<?=$hashNumber?>">
					<td colspan="8">
						<?
						
						$operations = array();
						$operations[] = array(
							'type' => 'инвестирование',
							'date' => $loan['investDate'],
							'sum' => -$loan['investSum'],
						);
						foreach ($loan['operations'] as $type => $ops)
						{
							foreach ($ops as $date => $sum)
							{
								$operations[] = array(
									'type' => $type,
									'date' => $date,
									'sum' => $sum,
								);
							}
						}
						
						uasort($operations, function($a,$b) {
							return strtotime($a['date']) - strtotime($b['date']);
						});
							
						?>
						<table class="ptk-loan-ops">
							<tr>
								<th>Дата</th>
								<th>Тип</th>
								<th>Сумма</th>
							</tr>
							<?
							$sum = 0;
							foreach ($operations as $op)
							{
								?>
								<tr>
									<td><?=$op['date']?></td>
									<td><?=$op['type']?></td>
									<td class="ptk-loan-ops__sum"><?=round($op['sum'],2)?>&nbsp;&#8381;</td>
								</tr>
								<?
								$sum+=$op['sum'];
							}
							?>
							<tr>
								<td style="text-align: right;" colspan="2">ИТОГО:</td>
								<td class="ptk-loan-ops__sum"><?=round($sum,2)?>&nbsp;&#8381;</td>
							</tr>
						</table>
						<?
						/**/
						?>
					</td>
				</tr>
				<?
			}
			?>
		</tbody>
	</table>
	<?
}

array_map('unlink', glob("$tmpDir/*.*"));
rmdir($tmpDir);

?>
</body>
</html>