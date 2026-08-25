/**
 * ern_rapor.js — Rapor dışa aktarma ortak katmanı (tüm modüller)
 *
 * Beton raporlarındaki deseni standartlaştırır:
 *   ERN_RAPOR.wb()                     → ERN Taahhüt logolu ExcelJS çalışma kitabı
 *   ERN_RAPOR.title(wb, ws, 'BAŞLIK')  → sayfa başlığı (logo + koyu yeşil bant)
 *   ERN_RAPOR.hdr(row)                 → koyu yeşil tablo başlığı stili
 *   ERN_RAPOR.save(wb, 'dosya.xlsx')   → indir
 *   ERN_RAPOR.popup({title, body, mode:'pdf'|'print', filename})
 *       → logolu A4 penceresi; mode 'pdf' = jsPDF ile doğrudan kaydet,
 *         'print' = yazdırma diyaloğu; penceredeki butonlarla ikisi de yapılabilir.
 *
 * Sayfa, script'ten ÖNCE window.ERN_ROOT tanımlamalı ('' veya '../').
 */
window.ERN_RAPOR = (function () {
    const ROOT = window.ERN_ROOT || '';
    const LOGO = ROOT + 'uploads/logo/ern_taahhut_export.png';
    let logoB64 = null;

    async function logo() {
        if (logoB64) return logoB64;
        const b = await fetch(LOGO).then(r => r.blob());
        logoB64 = await new Promise(res => { const f = new FileReader(); f.onload = () => res(f.result); f.readAsDataURL(b); });
        return logoB64;
    }

    async function wb() {
        const w = new ExcelJS.Workbook();
        w.creator = 'ERN Taahhüt'; w.company = 'ERN Taahhüt';
        w.created = new Date(); w.modified = new Date();
        try { w.__logo = w.addImage({ base64: await logo(), extension: 'png' }); } catch (e) {}
        return w;
    }

    function title(w, ws, text, span, altText) {
        // Düzen: A1:B2 = logo alanı (metinle ÇAKIŞMAZ), C1'den itibaren başlık (2 satır yüksek).
        span = Math.max(span || 6, 4);
        const end = String.fromCharCode(64 + Math.min(span, 26));
        ws.mergeCells('A1:B2');
        ws.mergeCells('C1:' + end + '2');
        const c = ws.getCell('C1');
        c.value = 'ERN TAAHHÜT — ' + text;
        c.font = { bold: true, size: 14, color: { argb: 'FF00584E' } };
        c.alignment = { vertical: 'middle' };
        ws.getRow(1).height = 26; ws.getRow(2).height = 26;   // logo 45px bu 52px'e sığar, taşmaz
        if (w.__logo !== undefined) ws.addImage(w.__logo, { tl: { col: 0.12, row: 0.12 }, ext: { width: 73, height: 45 } });
        if (altText) {
            ws.mergeCells('A3:' + end + '3');
            const a = ws.getCell('A3');
            a.value = altText;
            a.font = { size: 10, color: { argb: 'FF777777' } };
        }
        ws.addRow([]);
    }

    function hdr(row) {
        row.eachCell(c => {
            c.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF00584E' } };
            c.font = { color: { argb: 'FFFFFFFF' }, bold: true };
            c.alignment = { vertical: 'middle' };
        });
    }

    async function save(w, filename) {
        const buf = await w.xlsx.writeBuffer();
        const url = URL.createObjectURL(new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }));
        const a = document.createElement('a');
        a.href = url; a.download = filename;
        document.body.appendChild(a); a.click(); a.remove();
        URL.revokeObjectURL(url);
    }

    const esc = t => String(t == null ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;');
    const tbl = (hdrArr, rows) => '<table><thead><tr>' + hdrArr.map(x => '<th>' + x + '</th>').join('') + '</tr></thead><tbody>'
        + rows.map(r => '<tr>' + r.map((x, i) => '<td' + (i > 0 ? ' class="r"' : '') + '>' + esc(x) + '</td>').join('') + '</tr>').join('') + '</tbody></table>';

    function popup(o) {
        const logoUrl = new URL(LOGO, location.href).href;
        const w = window.open('', '_blank');
        if (!w) { alert('Pop-up engellendi. Adres çubuğundaki izin ikonuna tıklayın.'); return; }
        const auto = o.mode === 'pdf' ? 'savePDF();'
                   : o.mode === 'print' ? 'setTimeout(function(){window.print();},500);' : '';
        w.document.write('<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>' + esc(o.title) + '</title><style>'
            + 'body{font-family:Segoe UI,Arial,sans-serif;color:#111;padding:24px;background:#fff;}'
            + 'h1{color:#00584E;font-size:20px;margin:0;}'
            + 'h2{color:#00584E;font-size:14px;border-bottom:1px solid #ddd;padding-bottom:4px;margin:18px 0 6px;}'
            + '.meta{color:#666;font-size:12px;margin-bottom:12px;}'
            + 'table{width:100%;border-collapse:collapse;font-size:11px;margin-bottom:8px;}'
            + 'th,td{border:1px solid #bbb;padding:4px 7px;} th{background:#00584E;color:#fff;} td.r{text-align:right;}'
            + '.kpis{display:flex;gap:10px;margin:10px 0;} .kpis div{flex:1;border:1px solid #ddd;border-radius:8px;padding:8px;text-align:center;font-size:11px;color:#666;} .kpis b{display:block;font-size:16px;color:#111;}'
            + '.tb{text-align:center;padding-bottom:10px;border-bottom:1px solid #eee;margin-bottom:12px;}'
            + '.tb button{font:inherit;padding:7px 16px;border-radius:8px;border:none;cursor:pointer;margin:0 4px;background:#00584E;color:#fff;}'
            + '.tb .sec{background:#e0e0e0;color:#333;}'
            + '@media print{body{padding:6mm;} .tb{display:none;}}'
            + '</style></head><body>'
            + '<div class="tb"><button id="pdfBtn" onclick="savePDF()">⬇ PDF Kaydet</button>'
            + '<button class="sec" onclick="window.print()">🖨 Yazdır</button></div>'
            + '<div id="icerik">'
            + '<div style="display:flex;align-items:center;gap:12px;border-bottom:3px solid #00584E;padding-bottom:8px;margin-bottom:6px">'
            + '<img src="' + logoUrl + '" style="height:44px" onerror="this.remove()"><h1>ERN TAAHHÜT — ' + esc(o.title) + '</h1></div>'
            + '<div class="meta">' + (o.meta ? esc(o.meta) + ' — ' : '') + new Date().toLocaleString('tr-TR') + '</div>'
            + o.body + '</div>'
            + '<scr' + 'ipt src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></scr' + 'ipt>'
            + '<scr' + 'ipt src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></scr' + 'ipt>'
            + '<scr' + 'ipt>'
            + 'async function savePDF(){var b=document.getElementById("pdfBtn");if(b){b.disabled=true;b.textContent="Kaydediliyor...";}'
            + 'try{'
            + 'await Promise.all(Array.from(document.images).map(function(im){return im.complete?0:new Promise(function(r){im.onload=im.onerror=r;});}));'
            + 'var jsPDF=window.jspdf.jsPDF;'
            + 'var doc=new jsPDF({orientation:"portrait",unit:"mm",format:"a4"});'
            + 'var el=document.getElementById("icerik");'
            + 'var canvas=await html2canvas(el,{scale:2,useCORS:true,logging:false,backgroundColor:"#ffffff",windowWidth:900});'
            + 'var img=canvas.toDataURL("image/jpeg",0.92);'
            + 'var pw=doc.internal.pageSize.getWidth(), ph=doc.internal.pageSize.getHeight();'
            + 'var ih=canvas.height*pw/canvas.width, y=0, ilk=true;'
            + 'while(y<ih-1){ if(!ilk) doc.addPage(); doc.addImage(img,"JPEG",0,-y,pw,ih); y+=ph; ilk=false; }'
            + 'doc.save(' + JSON.stringify(o.filename || 'ERN_Rapor') + '+"_"+new Date().toISOString().slice(0,10)+".pdf");'
            + '}catch(e){alert("PDF kaydedilemedi: "+e.message);}'
            + 'finally{if(b){b.disabled=false;b.textContent="⬇ PDF Kaydet";}}}'
            + auto
            + '</scr' + 'ipt></body></html>');
        w.document.close();
        w.focus();
    }

    return { wb, title, hdr, save, popup, tbl, esc };
})();
