Z(!mode || !dates || !fromWrap || !fromInput || !untilInput || !help) return;
    dates.style.display = mode.value === 'unlimited' ? 'none' : 'grid';
    fromWrap.style.display = mode.value === 'scheduled' ? '' : 'none';
    fromInput.required = mode.value === 'scheduled';
    untilInput.required = mode.value === 'scheduled';
    if (mode.value === 'unlimited') {
        help.textContent = 'Unlimited availability is selected. ULearn will ignore any dates and keep this quiz available until you manually close or unpublish it.';
    } else if (mode.value === 'scheduled') {
        help.textContent = 'Students can access this quiz only between the required opening and closing dates.';
    } else {
        help.textContent = 'The quiz is available immediately. Set a closing date if you want it to expire automatically, or leave it blank for no automatic expiry.';
    }
}
document.addEventListener('DOMContentLoaded', updateAvailabilityFields);

// A small confirmation guard for publishing and deleting destructive records.
document.querySelectorAll('form').forEach(function(form){
    const action = form.querySelector('input[name="action"]')?.value;
    if (action === 'toggle_publish') {
        form.addEventListener('submit', function(event){
            const button = form.querySelector('button[type="submit"]');
            if (button && button.textContent.trim() === 'Publish' && !confirm('Publish this quiz now? Students may be able to access it.')) event.preventDefault();
        });
    }
});
</script>
<script>

</script><script>
(function(){
 const form=document.getElementById('bulk-import-editor');
 if(!form)return;
 const list=document.getElementById('bulk-editable-list');
 const renumber=()=>[...list.querySelectorAll('.bulk-edit-question')].forEach((card,i)=>card.querySelector('.bulk-question-number').textContent='Question '+(i+1));
 list.addEventListener('click',e=>{const btn=e.target.closest('.bulk-remove-question');if(!btn)return;if(!confirm('Remove this question from the import preview?'))return;btn.closest('.bulk-edit-question').remove();renumber();});
 form.addEventListener('submit',e=>{
   const cards=[...list.querySelectorAll('.bulk-edit-question')];
   if(!cards.length){e.preventDefault();alert('Keep at least one question to import.');return;}
   const get=(card,key)=>card.querySelector('[data-field="'+key+'"]')?.value.trim()||'';
   const lines=[];
   for(let i=0;i<cards.length;i++){
     const c=cards[i], q=get(c,'question');
     if(!q){e.preventDefault();alert('Question '+(i+1)+' has no text.');return;}
     lines.push('Q'+(i+1)+': '+q);
     for(const letter of ['A','B','C','D']){
       const option=get(c,'option-'+letter);
       if(!option){e.preventDefault();alert('Question '+(i+1)+' is missing option '+letter);return;}
       lines.push(letter+') '+option);
     }
     lines.push('Correct: '+get(c,'correct'),'Topic: '+(get(c,'topic')||'General'));
     if(get(c,'difficulty'))lines.push('Difficulty: '+get(c,'difficulty'));
     lines.push('Explanation: '+get(c,'explanation'));
     for(const letter of ['A','B','C','D'])lines.push('Reason '+letter+': '+get(c,'reason-'+letter));
     lines.push('');
   }
   document.getElementById('edited-bulk-text').value=lines.join('\n');
   if(!confirm('Import '+cards.length+' reviewed question(s) into this draft quiz?'))e.preventDefault();
 });
})();
</script>
<script>
(function(){
  // Import preview is its own focused screen, not a section below the upload form.
  const previewMode = <?= $isImportPreviewPage ? 'true' : 'false' ?>;
  if (!previewMode) return;
  document.addEventListener('DOMContentLoaded', function(){
    const main = document.querySelector('main.main');
    const preview = document.getElementById('import-preview-content');
    if (!main) return;
    const pane = document.createElement('section');
    pane.className = 'card';
    pane.style.cssText = 'max-width:940px;margin:0 auto;animation:none';
    const header = document.createElement('div');
    header.innerHTML = '<a class="back-link" href="quizzes.php?quiz=<?= (int)($selectedQuizId ?? 0) ?>">← Back to quiz editor</a><div class="kicker">Step 2 of 3 · Review imported questions</div><h1 style="font-size:clamp(28px,3vw,40px);margin:12px 0">Review &amp; edit questions</h1><p class="hint">Edit questions, answers, explanations and reasons, or delete unwanted questions. Nothing is published until you confirm the import and publish the quiz.</p>';
    pane.appendChild(header);
    if (preview) pane.appendChild(preview);
    else { const msg=document.createElement('p');msg.className='message error';msg.textContent='Preview expired. Return to the quiz and upload the file again.';pane.appendChild(msg); }
    Array.from(main.children).forEach(child=>child.remove());
    main.appendChild(pane);
    document.documentElement.style.scrollBehavior='auto';
    window.scrollTo(0,0);
  });
})();
</script>
<style>
.quiz-upload-zone{position:relative;border:1.5px dashed color-mix(in srgb,var(--accent) 48%,var(--border));border-radius:19px;padding:28px 18px;text-align:center;background:linear-gradient(135deg,var(--accent-light),var(--surface));display:flex;flex-direction:column;align-items:center;gap:9px;transition:border-color .2s,background .2s}
.quiz-upload-zone.dragover{border-color:var(--accent);background:var(--accent-light)}
.quiz-upload-symbol{display:grid;place-items:center;width:48px;height:48px;border-radius:15px;background:var(--surface);color:var(--accent);font-size:27px;font-weight:700;border:1px solid var(--border)}
.quiz-upload-zone strong{font-size:15px;letter-spacing:-.3px}
.quiz-upload-description{font-size:12px;color:var(--muted);max-width:360px}
.quiz-file-picker{display:inline-flex;align-items:center;justify-content:center;cursor:pointer;border-radius:11px;background:var(--accent);color:#fff;padding:11px 22px;margin:6px 0 0;font-size:12px;font-weight:800;box-shadow:0 7px 20px rgba(103,86,232,.18)}
.quiz-upload-zone input[type=file]{position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;padding:0;min-height:0;pointer-events:none}
.quiz-upload-status{font-size:12px;font-weight:650;color:var(--muted);overflow-wrap:anywhere}
#quiz-upload-button:disabled{opacity:.5;cursor:not-allowed;transform:none}
</style>
<script>
(function(){
  const form=document.getElementById('quiz-upload-form');
  if(!form)return;
  const input=form.querySelector('#source_file'),zone=form.querySelector('#quiz-upload-zone');
  const status=form.querySelector('#quiz-upload-status'),uploadButton=form.querySelector('#quiz-upload-button');
  const pasteButton=form.querySelector('#quiz-paste-preview-button');
  let submitting=false;
  function checkFile(file){
    if(!file)return false;
    if(!/\.(docx|txt)$/i.test(file.name)){status.textContent='Please select a .docx or .txt file.';return false;}
    if(file.size>10*1024*1024){status.textContent='File exceeds 10 MB. Please choose a smaller file.';return false;}
    return true;
  }
  function upload(){
    if(submitting||!checkFile(input.files&&input.files[0]))return;
    submitting=true;status.textContent='Uploading and validating '+input.files[0].name+'…';
    uploadButton.disabled=true;uploadButton.textContent='Processing document…';
    pasteButton.disabled=true;
    if(form.requestSubmit)form.requestSubmit();
    else form.submit();
  }
  input.addEventListener('change',function(){
    const file=input.files&&input.files[0];
    uploadButton.disabled=!checkFile(file);
    status.textContent=file?(uploadButton.disabled?status.textContent:file.name+' · Ready to review'):'No file selected';
    if(file&&!uploadButton.disabled)upload();
  });
  uploadButton.addEventListener('click',function(e){if(submitting)e.preventDefault();});
  pasteButton.addEventListener('click',function(){input.value='';});
  for(const eventName of ['dragenter','dragover'])zone.addEventListener(eventName,function(e){e.preventDefault();zone.classList.add('dragover');});
  for(const eventName of ['dragleave','drop'])zone.addEventListener(eventName,function(e){e.preventDefault();zone.classList.remove('dragover');});
  zone.addEventListener('drop',function(e){
    const file=e.dataTransfer&&e.dataTransfer.files&&e.dataTransfer.files[0];
    if(!checkFile(file))return;
    try{const dt=new DataTransfer();dt.items.add(file);input.files=dt.files;input.dispatchEvent(new Event('change',{bubbles:true}));}
    catch(err){status.textContent='Drag-and-drop is unavailable here. Please use Choose a file.';}
  });
})();
</script>
</body>
</html>
