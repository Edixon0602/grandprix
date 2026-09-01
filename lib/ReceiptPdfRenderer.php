<?php
declare(strict_types=1);

/**
 * Generador PDF liviano, sin dependencias externas.
 * Diseñado específicamente para los recibos GRANDPRIX.
 */
final class ReceiptPdfRenderer
{
    private const W = 419.53; // A5 portrait
    private const H = 595.28;

    public static function bytes(array $r): string
    {
        $c = new GpReceiptPdfCanvas(self::W, self::H);
        self::draw($c, $r);
        return $c->finish();
    }

    private static function money(mixed $value): string
    {
        if ($value === null || $value === '') return '-';
        $n = (float)$value;
        return '$'.number_format($n, 2, '.', ',');
    }

    private static function paymentMoney(array $r): string
    {
        $currency=mb_strtoupper((string)($r['currency']??'USD'));
        $amount=$r['amount']??null;
        if($amount===null||$amount==='')return '-';
        return $currency==='BS'?'Bs. '.number_format((float)$amount,2,'.',','):'$'.number_format((float)$amount,2,'.',',');
    }

    private static function date(mixed $value): string
    {
        $raw = trim((string)($value ?? ''));
        if ($raw === '') return '-';
        $ts = strtotime($raw);
        return $ts ? date('d/m/Y', $ts) : $raw;
    }

    private static function clean(mixed $value, string $fallback='-'): string
    {
        $v = trim((string)($value ?? ''));
        return $v === '' ? $fallback : $v;
    }

    private static function draw(GpReceiptPdfCanvas $c, array $r): void
    {
        $navy = [8, 43, 80];
        $navy2 = [15, 66, 111];
        $blue = [17, 103, 207];
        $ink = [10, 36, 64];
        $muted = [102, 125, 148];
        $line = [217, 229, 239];
        $bg = [238, 244, 249];
        $red = [190, 31, 62];
        $redBg = [255, 242, 244];
        $green = [13, 139, 96];
        $greenBg = [232, 249, 241];
        $amber = [169, 105, 0];
        $amberBg = [255, 246, 224];

        $paidWeeks = array_values(array_map('intval', (array)($r['paidWeeks'] ?? [])));
        $pending = array_values(array_map('intval', (array)($r['pendingWeeks'] ?? [])));
        $allocations = array_values((array)($r['allocations'] ?? []));
        $partial = array_values(array_filter($allocations, static fn(array $a): bool => empty($a['completed']) && (float)($a['allocated'] ?? 0) > 0));
        sort($pending);
        $lastAllocation = $allocations ? end($allocations) : null;
        $week = $paidWeeks ? (string)end($paidWeeks) : (is_array($lastAllocation)?(string)($lastAllocation['weekNumber']??'-'):'-');
        $paidLabel = $paidWeeks ? implode(', ', $paidWeeks) : 'Ninguna';
        $partialLabel = 'Sin abono parcial';
        if($partial){$x=$partial[count($partial)-1];$partialLabel='S'.(int)($x['weekNumber']??0).' '.number_format((float)($x['paidPercentageAfter']??0),0).'% / saldo '.self::money($x['balanceAfter']??0);}
        $receiptNo = self::clean($r['receiptNumber'] ?? 'RECIBO GRANDPRIX');
        $nextWeek = self::clean($r['nextWeek'] ?? '-');

        // Fondo general.
        $c->fillRect(0, 0, self::W, self::H, $bg);
        $c->roundRect(10, 10, self::W-20, self::H-20, 14, [255,255,255], null);

        // Header.
        self::logo($c, 24, 31, $navy, $blue, $muted);
        $c->text(self::W-24, 31, 'RECIBO DE PAGO SEMANAL', 6.2, 'B', $muted, 'R');
        $c->text(self::W-24, 43, $receiptNo, 8.2, 'B', $ink, 'R');
        $c->line(24, 61, self::W-24, 61, $line, .7);

        // Hero.
        $heroY = 72;
        $heroH = 116;
        $c->fillRect(10, $heroY, self::W-20, $heroH, $navy2);
        $c->roundRect(24, $heroY+17, 86, 82, 12, $navy, [36,89,135]);
        $c->text(67, $heroY+38, 'ÚLTIMA SEMANA APLICADA', 6.2, 'B', [255,255,255], 'C');
        $c->text(67, $heroY+77, $week, 32, 'B', [255,255,255], 'C');

        $c->text(124, $heroY+31, 'Pago registrado correctamente', 14, 'B', [255,255,255]);
        $c->text(124, $heroY+45, 'Comprobante oficial del pago aplicado al contrato GRANDPRIX.', 6.7, '', [210,225,239]);

        $facts = [
            ['Monto recibido', self::paymentMoney($r)],
            ['Fecha de pago', self::date($r['paidAt'] ?? null)],
            ['Próxima cuota', self::date($r['nextDueDate'] ?? null)],
        ];
        $fx=124; $fy=$heroY+61; $fw=83; $gap=7;
        foreach ($facts as $i=>$f) {
            $x=$fx+$i*($fw+$gap);
            $c->roundRect($x,$fy,$fw,35,7,[27,79,126],null);
            $c->text($x+7,$fy+12,$f[0],5.2,'',[185,208,229]);
            $c->text($x+7,$fy+27,$f[1],7.8,'B',[255,255,255]);
        }

        // Three cards.
        $cardsY = 204; $cardsH = 114; $gap=8; $margin=24; $cardW=(self::W-2*$margin-2*$gap)/3;
        $cards = [
            ['DATOS DEL CLIENTE', [
                ['Cliente', self::clean($r['clientName'] ?? null)],
                ['Cédula', self::clean($r['identityDocument'] ?? null)],
                ['Teléfono', self::clean($r['phone'] ?? null)],
                ['Dirección', self::clean($r['address'] ?? null)],
            ]],
            ['DATOS DE LA MOTO', [
                ['Modelo', self::clean($r['model'] ?? null)],
                ['Placa', self::clean($r['plate'] ?? null)],
                ['Contrato', self::clean($r['contractNumber'] ?? null)],
                ['Plan', (int)($r['totalWeeks'] ?? 50).' semanas'],
            ]],
            ['DETALLE DEL PAGO', [
                ['Completadas', $paidLabel],
                ['Abono parcial', $partialLabel],
                ['Forma', self::clean($r['paymentMethod'] ?? null)],
                ['Banco / ref.', self::truncate(self::clean($r['bank'] ?? null).' / '.self::clean($r['reference'] ?? null),24)],
            ]],
        ];
        foreach ($cards as $i=>$card) {
            $x=$margin+$i*($cardW+$gap);
            $c->roundRect($x,$cardsY,$cardW,$cardsH,9,[251,253,255],$line);
            $c->text($x+8,$cardsY+15,$card[0],6.1,'B',$blue);
            $rowY=$cardsY+31;
            foreach ($card[1] as $ri=>$row) {
                $y=$rowY+$ri*19;
                if ($ri>0) $c->line($x+8,$y-6,$x+$cardW-8,$y-6,[232,238,244],.45);
                $c->text($x+8,$y,$row[0],5.7,'',$muted);
                $value = self::truncate((string)$row[1], 24);
                $c->text($x+$cardW-8,$y,$value,6.2,'B',$ink,'R');
            }
        }

        // Distribución exacta del pago: semanas completas + abono parcial.
        $allocY=328; $allocH=58;
        $c->roundRect(24,$allocY,self::W-48,$allocH,9,[248,251,254],$line);
        $c->text(34,$allocY+14,'DISTRIBUCIÓN DEL PAGO',6.1,'B',$blue);
        if($allocations){
            $count=min(4,count($allocations));$gapA=6;$aw=(self::W-68-($count-1)*$gapA)/$count;$ax=34;
            foreach(array_slice($allocations,0,4) as $a){
                $done=!empty($a['completed']);$fill=$done?$greenBg:$amberBg;$stroke=$done?[183,229,207]:[242,210,143];$inkA=$done?$green:$amber;
                $c->roundRect($ax,$allocY+21,$aw,27,6,$fill,$stroke);
                $c->text($ax+6,$allocY+31,'S'.(int)($a['weekNumber']??0),6.2,'B',$inkA);
                $label=$done?'PAGADA 100%':'ABONO '.number_format((float)($a['paidPercentageAfter']??0),0).'%';
                $c->text($ax+$aw-6,$allocY+31,$label,5.5,'B',$inkA,'R');
                $detail=self::money($a['allocated']??0).($done?'':' / saldo '.self::money($a['balanceAfter']??0));
                $c->text($ax+6,$allocY+42,self::truncate($detail,25),5.2,'',$inkA);
                $ax+=$aw+$gapA;
            }
        }else{$c->text(34,$allocY+38,'Sin distribución registrada.',6.2,'',$muted);}

        // Pending section.
        $pendingY=396; $pendingH=58;
        $c->roundRect(24,$pendingY,self::W-48,$pendingH,9,$redBg,[255,195,204]);
        $c->text(34,$pendingY+15,'SEMANAS VENCIDAS CON SALDO',6.1,'B',$red);
        $c->text(self::W-34,$pendingY+15,count($pending).' pendiente(s)',6.1,'B',$red,'R');
        if ($pending) {
            $chipX=34; $chipY=$pendingY+27;
            foreach ($pending as $idx=>$pw) {
                if ($idx >= 10) break;
                $c->roundRect($chipX,$chipY,25,20,5,[228,52,80],null);
                $c->text($chipX+12.5,$chipY+14,(string)$pw,7,'B',[255,255,255],'C');
                $chipX += 30;
            }
            if (count($pending)>10) $c->text($chipX+2,$chipY+14,'+'.(count($pending)-10),6.5,'B',$red);
        } else {
            $c->roundRect(34,$pendingY+27,99,21,6,[225,248,239],null);
            $c->text(83.5,$pendingY+41,'Sin semanas vencidas',6.5,'B',$green,'C');
        }

        // Status section.
        $statusY=464; $statusH=45;
        $isLate=(bool)$pending;
        $c->roundRect(24,$statusY,self::W-48,$statusH,9,$isLate?$amberBg:$greenBg,null);
        $iconX=46; $iconY=$statusY+22;
        $c->circle($iconX,$iconY,10,[255,255,255],null);
        if ($isLate) {
            $c->text($iconX,$iconY+3,'!',9,'B',$amber,'C');
            $c->text(68,$statusY+18,'CON CUOTAS PENDIENTES',7.4,'B',$amber);
            $c->text(68,$statusY+31,'El pago se aplicó correctamente; todavía existen semanas vencidas con saldo.',5.5,'',$amber);
        } else {
            $c->check($iconX-5,$iconY+1,10,$green);
            $c->text(68,$statusY+18,'AL DÍA',7.4,'B',$green);
            $c->text(68,$statusY+31,$partial?'Existe un abono parcial en la siguiente cuota.':'No quedan semanas vencidas después de este pago.',5.5,'',$green);
        }

        // Footer.
        $footY=519; $footH=66;
        $c->fillRect(10,$footY,self::W-20,$footH,$navy);
        $c->text(24,$footY+22,'GRANDPRIX INVERSIONES',6.2,'',[184,208,230]);
        $c->text(24,$footY+37,'Gracias por su pago.',8.5,'B',[255,255,255]);
        $c->text(self::W-24,$footY+21,'Próxima semana: '.$nextWeek,5.8,'',[184,208,230],'R');
        $nextText=self::date($r['nextDueDate']??null).(($r['nextAmount']??null)!==null?' · saldo '.self::money($r['nextAmount']):'');
        $c->text(self::W-24,$footY+37,$nextText,6.8,'B',[255,255,255],'R');
        $c->text(self::W-24,$footY+52,'Documento generado por GRANDPRIX Control 360',4.9,'',[138,168,195],'R');
    }

    private static function logo(GpReceiptPdfCanvas $c, float $x, float $y, array $navy, array $blue, array $muted): void
    {
        // Isotipo ascendente.
        $c->fillRect($x, $y+7, 4, 11, [140,157,173]);
        $c->fillRect($x+6, $y+3, 4, 15, [95,121,146]);
        $c->fillRect($x+12, $y-1, 4, 19, $blue);
        $c->line($x+2,$y+1,$x+17,$y-7,$blue,1.3);
        $c->line($x+17,$y-7,$x+15,$y-2,$blue,1.3);
        $c->line($x+17,$y-7,$x+11,$y-6,$blue,1.3);
        $c->text($x+23,$y+10,'GRANDPRIX',13,'B',$navy);
        $c->text($x+82,$y+21,'INVERSIONES',4.9,'B',$muted,'C');
    }

    private static function truncate(string $text, int $max): string
    {
        $text = trim($text);
        $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($len <= $max) return $text;
        $cut = function_exists('mb_substr') ? mb_substr($text, 0, $max-1) : substr($text, 0, $max-1);
        return $cut.'...';
    }
}

final class GpReceiptPdfCanvas
{
    private string $content='';
    public function __construct(private float $w, private float $h) {}

    private static function enc(string $s): string
    {
        $s = str_replace(["\r","\n"], ' ', $s);
        $x = iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$s);
        $x = $x === false ? $s : $x;
        return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $x);
    }
    private function y(float $top): float { return $this->h-$top; }
    private static function rgb(array $c): string { return sprintf('%.4f %.4f %.4f', $c[0]/255, $c[1]/255, $c[2]/255); }
    public function text(float $x,float $top,string $text,float $size,string $font='',array $color=[0,0,0],string $align='L'):void {
        if($align!=='L'){
            $len=function_exists('mb_strlen')?mb_strlen($text):strlen($text);
            $approx=$len*$size*.48;
            if($align==='R')$x-=$approx; elseif($align==='C')$x-=$approx/2;
        }
        $f=$font==='B'?'F2':'F1';
        $this->content .= 'BT /'.$f.' '.sprintf('%.2f',$size).' Tf '.self::rgb($color).' rg '.sprintf('%.2f %.2f',$x,$this->y($top)).' Td ('.self::enc($text).") Tj ET\n";
    }
    public function fillRect(float $x,float $top,float $w,float $h,array $fill):void {
        $this->content .= self::rgb($fill).' rg '.sprintf('%.2f %.2f %.2f %.2f re f',$x,$this->h-$top-$h,$w,$h)."\n";
    }
    public function roundRect(float $x,float $top,float $w,float $h,float $r,?array $fill,?array $stroke):void {
        $y=$this->h-$top-$h; $k=.5522847498; $rr=min($r,$w/2,$h/2); $o=$rr*$k;
        if($fill)$this->content.=self::rgb($fill)." rg "; if($stroke)$this->content.=self::rgb($stroke)." RG 0.6 w ";
        $this->content .= sprintf('%.2f %.2f m ', $x+$rr,$y);
        $this->content .= sprintf('%.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c ', $x+$w-$rr,$y,$x+$w-$rr+$o,$y,$x+$w,$y+$rr-$o,$x+$w,$y+$rr);
        $this->content .= sprintf('%.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c ', $x+$w,$y+$h-$rr,$x+$w,$y+$h-$rr+$o,$x+$w-$rr+$o,$y+$h,$x+$w-$rr,$y+$h);
        $this->content .= sprintf('%.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c ', $x+$rr,$y+$h,$x+$rr-$o,$y+$h,$x,$y+$h-$rr+$o,$x,$y+$h-$rr);
        $this->content .= sprintf('%.2f %.2f l %.2f %.2f %.2f %.2f %.2f %.2f c h ', $x,$y+$rr,$x,$y+$rr-$o,$x+$rr-$o,$y,$x+$rr,$y);
        $this->content .= $fill&&$stroke?'B':($fill?'f':'S'); $this->content.="\n";
    }
    public function line(float $x1,float $top1,float $x2,float $top2,array $color,float $width=.5):void {
        $this->content .= self::rgb($color).' RG '.sprintf('%.2f w %.2f %.2f m %.2f %.2f l S',$width,$x1,$this->y($top1),$x2,$this->y($top2))."\n";
    }
    public function circle(float $cx,float $top,float $r,?array $fill,?array $stroke):void {
        $cy=$this->y($top); $k=.5522847498; $o=$r*$k;
        if($fill)$this->content.=self::rgb($fill)." rg "; if($stroke)$this->content.=self::rgb($stroke)." RG ";
        $this->content .= sprintf('%.2f %.2f m %.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f %.2f %.2f %.2f %.2f c %.2f %.2f %.2f %.2f %.2f %.2f c h ',
          $cx+$r,$cy, $cx+$r,$cy+$o,$cx+$o,$cy+$r,$cx,$cy+$r, $cx-$o,$cy+$r,$cx-$r,$cy+$o,$cx-$r,$cy, $cx-$r,$cy-$o,$cx-$o,$cy-$r,$cx,$cy-$r, $cx+$o,$cy-$r,$cx+$r,$cy-$o,$cx+$r,$cy);
        $this->content .= $fill&&$stroke?'B':($fill?'f':'S'); $this->content.="\n";
    }
    public function check(float $x,float $top,float $size,array $color):void {
        $this->content .= self::rgb($color).' RG 1.6 w '.sprintf('%.2f %.2f m %.2f %.2f l %.2f %.2f l S',$x,$this->y($top),$x+$size*.35,$this->y($top+$size*.35),$x+$size,$this->y($top-$size*.35))."\n";
    }
    public function finish():string {
        $stream=$this->content;
        $objs=[];
        $objs[1]='<< /Type /Catalog /Pages 2 0 R >>';
        $objs[2]='<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objs[3]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.sprintf('%.2f %.2f',$this->w,$this->h).'] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> /Contents 6 0 R >>';
        $objs[4]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[5]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objs[6]='<< /Length '.strlen($stream).' >>'."\nstream\n".$stream."endstream";
        $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets=[0];
        foreach($objs as $n=>$obj){$offsets[$n]=strlen($pdf);$pdf.=$n." 0 obj\n".$obj."\nendobj\n";}
        $xref=strlen($pdf);$pdf.="xref\n0 7\n0000000000 65535 f \n";
        for($i=1;$i<=6;$i++)$pdf.=sprintf('%010d 00000 n ', $offsets[$i])."\n";
        $pdf.="trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
        return $pdf;
    }
}
