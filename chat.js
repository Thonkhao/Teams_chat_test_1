// ส่งฟอร์มแชทส่วนตัวไปที่ send_chat.php (ฝั่ง server เป็นตัวเรียก Microsoft Graph)

document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('chat_form');
    if (!form) { return; } // ยังไม่ login หรือยังไม่เชื่อมต่อบัญชี Microsoft

    var btn    = document.getElementById('ct_send');
    var result = document.getElementById('result');

    // ปุ่ม B / U / I — ครอบข้อความที่เลือกด้วยแท็ก (ไม่ได้เลือก = ใส่แท็กคู่แล้ววางเคอร์เซอร์ไว้ตรงกลาง)
    var textarea = document.getElementById('ct_message');
    document.querySelectorAll('.fmt button').forEach(function (b) {
        b.addEventListener('click', function () {
            var tag   = b.getAttribute('data-tag');
            var start = textarea.selectionStart;
            var end   = textarea.selectionEnd;
            var open  = '<' + tag + '>';
            textarea.setRangeText(open + textarea.value.slice(start, end) + '</' + tag + '>', start, end, 'end');
            if (start === end) { textarea.selectionStart = textarea.selectionEnd = start + open.length; }
            textarea.focus();
        });
    });

    function showResult(ok, text) {
        result.className   = ok ? 'ok' : 'err';
        result.textContent = text;
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        var recipient = document.getElementById('ct_recipient').value.trim();
        var message   = document.getElementById('ct_message').value.trim();
        if (!recipient || !message) {
            showResult(false, 'กรุณากรอกอีเมลผู้รับและข้อความ');
            return;
        }

        btn.disabled = true;
        btn.textContent = 'กำลังส่ง...';

        fetch('send_chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: document.querySelector('meta[name="csrf-token"]').content,
                recipient: recipient,
                message: message
            })
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                showResult(data.success, data.message);
                if (data.success) { document.getElementById('ct_message').value = ''; }
                // token หมดอายุ/ถูกเพิกถอน — โหลดหน้าใหม่ให้เห็นปุ่มเชื่อมต่อ
                if (data.reconnect) { setTimeout(function () { location.reload(); }, 1500); }
            })
            .catch(function (err) { showResult(false, 'เกิดข้อผิดพลาด: ' + err.message); })
            .finally(function () {
                btn.disabled = false;
                btn.textContent = 'ส่งแชท';
            });
    });
});
