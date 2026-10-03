(() => {
    'use strict';
    const app = document.getElementById('schoolSalesCatalogApp');
    if (!app) return;
    const boot = window.SCHOOL_SALES_CATALOG_BOOTSTRAP || {};
    const byId = id => document.getElementById(id);
    const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
    const money = value => new Intl.NumberFormat('en-PH', {style:'currency',currency:'PHP'}).format(Number(value || 0));
    const permissions = boot.permissions || {};
    const state = { items:[], categories:[], types:[], selectedId:null, detail:null, readiness:null, book:null, retry:null, editorSave:null, confirmRun:null };
    const errors = {
        AUTHENTICATION_REQUIRED:'Your session expired. Sign in again.', CSRF_INVALID:'Your security token expired. Refresh the page.',
        SCHOOL_SALES_CATALOG_VIEW_REQUIRED:'Catalog view access is required.', SCHOOL_SALES_CATALOG_MANAGE_REQUIRED:'Catalog management permission is required.',
        SCHOOL_SALES_CATALOG_ACTIVATE_REQUIRED:'Catalog activation permission is required for this state.', CORRELATION_ID_CONFLICT:'This request ID was already used for different content.',
        DUPLICATE_ITEM_CODE:'That item code is already in use.', DUPLICATE_VARIANT_CODE:'That variant code already exists for this item.', DUPLICATE_VARIANT_SKU:'That SKU is already in use.',
        ACTIVE_PRICE_WINDOW_OVERLAP:'The server detected an overlapping Active price period.', DUPLICATE_PRICE_EFFECTIVE_START:'A price already begins at that effective time.',
        ITEM_ACTIVATION_PREREQUISITES_FAILED:'Resolve the listed activation prerequisites first.', BOOK_DETAILS_MISSING:'Complete the Book metadata first.',
        BOOK_ITEM_REQUIRED:'Book metadata is available only for BOOK items.', ACTIVE_BOOK_DETAILS_IMMUTABLE:'Deactivate the BOOK item before editing its metadata.',
        ACTIVE_ITEM_INVARIANT_VIOLATION:'That change would leave an Active item inconsistent.', PRICE_CANNOT_BE_CANCELLED:'This price can no longer be cancelled.',
        CATALOG_CONCURRENCY_CONFLICT:'The catalog changed at the same time. Reload and try again.', INVALID_CORRELATION_ID:'The mutation request ID is invalid.'
    };

    function uuid() {
        if (crypto.randomUUID) return crypto.randomUUID();
        const bytes = crypto.getRandomValues(new Uint8Array(16)); bytes[6]=(bytes[6]&15)|64; bytes[8]=(bytes[8]&63)|128;
        const hex=[...bytes].map(v=>v.toString(16).padStart(2,'0')).join(''); return `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;
    }
    function notify(message, type='success', retry=null) {
        const box=byId('catalogAlert'); box.className=`alert alert-${type}`; byId('catalogAlertText').textContent=message;
        state.retry=retry; byId('catalogRetry').classList.toggle('d-none', !retry); box.scrollIntoView({behavior:'smooth',block:'nearest'});
    }
    function errorMessage(payload) { return errors[payload?.error] || payload?.message || 'The catalog request could not be completed.'; }
    async function read(action, ids={}) {
        const url=new URL(boot.api, window.location.origin); url.searchParams.set('action',action); Object.entries(ids).forEach(([k,v])=>url.searchParams.set(k,v));
        const response=await fetch(url,{headers:{Accept:'application/json'}}); const payload=await response.json();
        if (!response.ok || !payload.ok) { const e=new Error(errorMessage(payload)); e.payload=payload; throw e; } return payload.data;
    }
    async function mutate(action, ids={}, data={}, correlationId=uuid()) {
        const body={action,...ids,data,correlation_id:correlationId};
        const attempt=async()=>{
            let response, payload;
            try { response=await fetch(boot.api,{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':boot.csrf_token,'X-Correlation-ID':correlationId},body:JSON.stringify(body)}); }
            catch (cause) { const e=new Error('Connection was interrupted. Retry the same request to safely check its committed result.'); e.uncertain=true; e.retry=attempt; e.cause=cause; throw e; }
            try { payload=await response.json(); } catch (cause) { const e=new Error('The server response was interrupted. Retry the same request safely.'); e.uncertain=true; e.retry=attempt; e.cause=cause; throw e; }
            if (!response.ok || !payload.ok) { const e=new Error(errorMessage(payload)); e.payload=payload; throw e; }
            return payload.data;
        };
        return attempt();
    }
    async function runMutation(action, ids, data, success, correlationId) {
        try {
            const result=await mutate(action,ids,data,correlationId);
            bootstrap.Modal.getInstance(byId('catalogEditorModal'))?.hide(); bootstrap.Modal.getInstance(byId('catalogConfirmModal'))?.hide();
            const pending=result.audit_status==='pending'; notify(pending ? `${success} The catalog change is committed; audit synchronization is pending.` : success, pending?'warning':'success');
            await reload(state.selectedId || result.result?.sale_item_id || null);
            return result;
        } catch (e) { notify(e.message,'danger',e.uncertain?e.retry:null); throw e; }
    }

    const badge=status=>({Active:'success',Draft:'warning',Inactive:'secondary',Archived:'dark',Cancelled:'secondary',Retired:'info'}[status]||'secondary');
    function renderList() {
        const q=byId('catalogSearch').value.trim().toLowerCase(), status=byId('catalogStatus').value, type=byId('catalogType').value;
        const rows=state.items.filter(x=>(!q||`${x.item_code} ${x.item_name}`.toLowerCase().includes(q))&&(!status||x.status===status)&&(!type||x.type_code===type));
        byId('catalogList').innerHTML=rows.map(x=>`<button type="button" class="list-group-item list-group-item-action ${Number(x.sale_item_id)===Number(state.selectedId)?'active':''}" data-command="select-item" data-id="${x.sale_item_id}"><div class="d-flex justify-content-between gap-2"><div><div class="fw-semibold">${esc(x.item_name)}</div><div class="catalog-list-code">${esc(x.item_code)} · ${esc(x.type_name)}</div></div><span class="badge text-bg-${badge(x.status)} align-self-start">${esc(x.status)}</span></div></button>`).join('');
        byId('catalogListEmpty').classList.toggle('d-none',rows.length!==0); byId('catalogList').classList.toggle('d-none',rows.length===0);
    }
    async function loadBase() {
        [state.categories,state.types,state.items]=await Promise.all([read('categories'),read('item_types'),read('items')]);
        byId('catalogType').innerHTML='<option value="">All types</option>'+state.types.map(t=>`<option value="${esc(t.type_code)}">${esc(t.type_name)}</option>`).join('');
        byId('catalogListLoading').classList.add('d-none'); renderList();
    }
    async function selectItem(id) {
        state.selectedId=Number(id); renderList(); byId('catalogWelcome').classList.add('d-none'); byId('catalogDetail').classList.add('d-none'); byId('catalogDetailLoading').classList.remove('d-none');
        try {
            const [detail,readiness]=await Promise.all([read('item_details',{item_id:id}),read('activation_readiness',{item_id:id})]);
            state.detail=detail; state.readiness=readiness; state.book=detail.item.type_code==='BOOK'?await read('book_details',{item_id:id}):null;
            renderDetail(); byId('catalogDetail').classList.remove('d-none');
        } catch(e) { notify(e.message,'danger'); byId('catalogWelcome').classList.remove('d-none'); }
        finally { byId('catalogDetailLoading').classList.add('d-none'); }
    }
    async function reload(preferredId=null) { await loadBase(); if (preferredId && state.items.some(x=>Number(x.sale_item_id)===Number(preferredId))) await selectItem(preferredId); }

    function button(label,command,style='outline-primary',attrs='') { return `<button type="button" class="btn btn-sm btn-${style}" data-command="${command}" ${attrs}>${label}</button>`; }
    function renderDetail() {
        const item=state.detail.item; byId('detailName').textContent=item.item_name; byId('detailCode').textContent=item.item_code;
        byId('detailDescription').textContent=item.description||'No description provided.'; byId('detailStatus').className=`badge text-bg-${badge(item.status)}`; byId('detailStatus').textContent=item.status;
        byId('detailMeta').innerHTML=[['Category',item.category_name],['Controlled type',item.type_name],['Applicability',item.applicability_mode],['Updated',item.updated_at||'—']].map(x=>`<div class="col-6 col-lg-3"><div class="catalog-meta"><div class="catalog-meta-label">${esc(x[0])}</div><div class="fw-semibold mt-1">${esc(x[1])}</div></div></div>`).join('');
        let itemActions=''; if(permissions.manage&&item.status==='Draft') itemActions+=button('Edit','edit-item');
        if(permissions.activate&&item.status!=='Archived'){ if(item.status==='Active') itemActions+=button('Deactivate','deactivate-item','outline-warning'); else itemActions+=button('Activate','activate-item','success'); if(item.status!=='Active') itemActions+=button('Archive','archive-item','outline-danger'); }
        byId('itemActions').innerHTML=itemActions; renderReadiness(); renderVariants(); renderApplicability(); renderBook();
    }
    function renderReadiness() {
        const r=state.readiness; byId('readinessBadge').className=`badge text-bg-${r.ready?'success':'warning'}`; byId('readinessBadge').textContent=r.ready?'Ready':'Not ready';
        byId('readinessBody').innerHTML=r.ready?'<div class="text-success"><i class="ti ti-circle-check me-2"></i>All server-side activation prerequisites pass.</div>':`<div class="readiness-list">${r.failures.map(f=>`<div class="readiness-row"><i class="ti ti-alert-triangle text-warning mt-1"></i><div><div class="fw-semibold">${esc(failureLabel(f.code))}</div><div class="small text-muted">${esc(f.message)}</div></div></div>`).join('')}</div>`;
    }
    function failureLabel(code) { return ({CATEGORY_INACTIVE:'Category is inactive',ITEM_TYPE_INACTIVE:'Item type is inactive',NO_ACTIVE_VARIANTS:'No Active variant',ACTIVE_VARIANT_MISSING_CURRENT_PRICE:'An Active variant has no current price',ACTIVE_VARIANT_MULTIPLE_CURRENT_PRICES:'Multiple current prices detected',ACTIVE_PRICE_WINDOW_OVERLAP:'Price periods overlap',ALL_HAS_ACTIVE_APPLICABILITY:'ALL has Active assignments',RESTRICTED_HAS_NO_ACTIVE_APPLICABILITY:'RESTRICTED has no Active assignment',BOOK_DETAILS_MISSING:'Book metadata is missing',BOOK_TITLE_INVALID:'Book title is invalid',BOOK_ISBN_INVALID:'ISBN is invalid',ITEM_ARCHIVED:'Item is Archived'}[code]||code.replaceAll('_',' ').toLowerCase()); }
    function renderVariants() {
        const variants=state.detail.variants||[]; byId('variantHeaderActions').innerHTML=permissions.manage?button('Add variant','add-variant')+button('Add STANDARD','add-standard','outline-secondary'):'';
        if(!variants.length){byId('variantList').innerHTML='<div class="catalog-empty-inline">No variants yet.</div>';return;}
        byId('variantList').innerHTML=variants.map(v=>{ const current=v.current_price; let actions='';
            if(permissions.manage&&v.status!=='Archived') actions+=button('Edit','edit-variant','outline-primary',`data-id="${v.sale_variant_id}"`);
            if((permissions.manage||permissions.activate)&&v.status!=='Archived') actions+=button('Deactivate','deactivate-variant','outline-warning',`data-id="${v.sale_variant_id}"`)+button('Archive','archive-variant','outline-danger',`data-id="${v.sale_variant_id}"`);
            if(permissions.manage) actions+=button('Draft price','add-price','outline-primary',`data-id="${v.sale_variant_id}"`);
            if(permissions.activate) actions+=button('Reprice','replace-price','outline-success',`data-id="${v.sale_variant_id}"`);
            const prices=(v.prices||[]).map(p=>`<div class="price-row"><div><strong>${money(p.amount)}</strong> ${esc(p.currency)} ${p.is_current?'<span class="badge text-bg-success">Current</span>':''}</div><div>${esc(p.effective_from)} → ${esc(p.effective_to||'Open-ended')}</div><div><span class="badge text-bg-${badge(p.status)}">${esc(p.status)}</span> ${priceActions(p)}</div></div>`).join('');
            return `<article class="variant-card"><div class="d-flex flex-wrap justify-content-between gap-2"><div><div class="fw-semibold">${esc(v.variant_name)} <span class="badge text-bg-${badge(v.status)}">${esc(v.status)}</span></div><div class="small text-muted font-monospace">${esc(v.variant_code)}${v.sku?' · SKU '+esc(v.sku):''}${v.size_label?' · '+esc(v.size_label):''}</div><div class="current-price mt-2">${current?money(current.amount):`<span class="text-${v.current_price_state==='Conflict'?'danger':'warning'}">${esc(v.current_price_state)} current price</span>`}</div></div><div class="catalog-actions">${actions}</div></div><div class="price-history">${prices||'<div class="small text-muted">No price history.</div>'}</div></article>`;
        }).join('');
    }
    function priceActions(p){let x='';if(permissions.manage&&p.status==='Draft')x+=button('Edit','edit-price','outline-primary',`data-id="${p.sale_variant_price_id}"`);if(permissions.activate&&p.status==='Draft')x+=button('Activate','activate-price','outline-success',`data-id="${p.sale_variant_price_id}"`);if(permissions.activate&&p.status==='Active')x+=button('Retire','retire-price','outline-warning',`data-id="${p.sale_variant_price_id}"`);if(permissions.manage||permissions.activate)x+=button('Cancel','cancel-price','outline-danger',`data-id="${p.sale_variant_price_id}" data-status="${esc(p.status)}"`);return x;}
    function renderApplicability(){const item=state.detail.item, rows=state.detail.applicability||[];let actions='';if(permissions.manage){actions+=button(item.applicability_mode==='ALL'?'Set RESTRICTED':'Set ALL','change-mode');if(item.applicability_mode==='RESTRICTED')actions+=button('Add scope','add-scope')+button('Replace active set','replace-scopes','outline-secondary');}byId('applicabilityActions').innerHTML=actions;byId('applicabilityBody').innerHTML=`<div class="mb-3"><span class="badge text-bg-primary">${esc(item.applicability_mode)}</span> <span class="text-muted ms-2">${rows.filter(x=>x.status==='Active').length} Active assignment(s)</span></div>`+(rows.length?`<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Program</th><th>Year</th><th>Status</th><th></th></tr></thead><tbody>${rows.map(x=>`<tr><td>${esc(x.program_code||'Any')}</td><td>${esc(x.year_level||'Any')}</td><td><span class="badge text-bg-${badge(x.status)}">${esc(x.status)}</span></td><td class="text-end">${permissions.manage&&x.status==='Active'?button('Deactivate','deactivate-scope','outline-danger',`data-id="${x.sale_item_applicability_id}"`):''}</td></tr>`).join('')}</tbody></table></div>`:'<div class="text-muted">No applicability history.</div>');}
    function renderBook(){const item=state.detail.item,isBook=item.type_code==='BOOK';byId('bookSection').classList.toggle('d-none',!isBook);if(!isBook)return;const b=state.book;byId('bookActions').innerHTML=permissions.manage&&item.status!=='Active'&&item.status!=='Archived'?button(b?'Edit metadata':'Add metadata',b?'edit-book':'add-book'):'';byId('bookBody').innerHTML=b?`<dl class="row mb-0"><dt class="col-sm-3">Title</dt><dd class="col-sm-9">${esc(b.book_title)}</dd><dt class="col-sm-3">Author</dt><dd class="col-sm-9">${esc(b.author||'—')}</dd><dt class="col-sm-3">Publisher</dt><dd class="col-sm-9">${esc(b.publisher||'—')}</dd><dt class="col-sm-3">Edition / ISBN</dt><dd class="col-sm-9">${esc(b.edition||'—')} · ${esc(b.isbn||'—')}</dd><dt class="col-sm-3">Notes</dt><dd class="col-sm-9">${esc(b.notes||'—')}</dd></dl>`:'<div class="text-warning"><i class="ti ti-alert-triangle me-2"></i>Book metadata has not been created.</div>';}

    function field(f){const value=esc(f.value??'');if(f.type==='select')return `<div class="catalog-field"><label class="form-label" for="edit_${f.name}">${esc(f.label)}</label><select id="edit_${f.name}" class="form-select" data-field="${f.name}">${f.options.map(o=>`<option value="${esc(o.value)}" ${String(o.value)===String(f.value)?'selected':''}>${esc(o.label)}</option>`).join('')}</select></div>`;if(f.type==='textarea')return `<div class="catalog-field"><label class="form-label" for="edit_${f.name}">${esc(f.label)}</label><textarea id="edit_${f.name}" class="form-control" rows="${f.rows||3}" data-field="${f.name}" placeholder="${esc(f.placeholder||'')}">${value}</textarea>${f.help?`<div class="form-text">${esc(f.help)}</div>`:''}</div>`;return `<div class="catalog-field"><label class="form-label" for="edit_${f.name}">${esc(f.label)}</label><input id="edit_${f.name}" class="form-control" type="${f.type||'text'}" data-field="${f.name}" value="${value}" ${f.required?'required':''} placeholder="${esc(f.placeholder||'')}">${f.help?`<div class="form-text">${esc(f.help)}</div>`:''}</div>`;}
    function editor(title,fields,onSave){byId('catalogEditorTitle').textContent=title;byId('catalogEditorBody').innerHTML=fields.map(field).join('');state.editorSave=()=>{const data={};byId('catalogEditorBody').querySelectorAll('[data-field]').forEach(el=>data[el.dataset.field]=el.value);return onSave(data);};bootstrap.Modal.getOrCreateInstance(byId('catalogEditorModal')).show();}
    function confirmAction(title,text,onRun){byId('catalogConfirmTitle').textContent=title;byId('catalogConfirmText').textContent=text;state.confirmRun=onRun;bootstrap.Modal.getOrCreateInstance(byId('catalogConfirmModal')).show();}
    const itemFields=i=>[{name:'item_code',label:'Item code',value:i?.item_code,required:true},{name:'item_name',label:'Item name',value:i?.item_name,required:true},{name:'sale_category_id',label:'Category',type:'select',value:i?.sale_category_id,options:state.categories.filter(x=>x.status==='Active').map(x=>({value:x.sale_category_id,label:x.category_name}))},{name:'sale_item_type_id',label:'Controlled item type',type:'select',value:i?.sale_item_type_id,options:state.types.filter(x=>x.status==='Active').map(x=>({value:x.sale_item_type_id,label:x.type_name}))},{name:'applicability_mode',label:'Applicability mode',type:'select',value:i?.applicability_mode||'ALL',options:[{value:'ALL',label:'ALL'},{value:'RESTRICTED',label:'RESTRICTED'}]},{name:'description',label:'Description',type:'textarea',value:i?.description}];
    const variantFields=v=>[{name:'variant_code',label:'Variant code',value:v?.variant_code,required:true},{name:'variant_name',label:'Variant name',value:v?.variant_name,required:true},{name:'sku',label:'SKU (optional)',value:v?.sku},{name:'size_label',label:'Size label (optional)',value:v?.size_label},{name:'sort_order',label:'Sort order',type:'number',value:v?.sort_order??0},{name:'variant_metadata',label:'Metadata JSON (optional)',type:'textarea',value:v?.variant_metadata?JSON.stringify(v.variant_metadata,null,2):'',help:'JSON object only; leave blank for none.'}];
    const priceFields=p=>[{name:'amount',label:'Amount (PHP)',type:'number',value:p?.amount,required:true},{name:'effective_from',label:'Effective from (UTC)',type:'datetime-local',value:p?.effective_from?.replace(' ','T'),required:true},{name:'effective_to',label:'Effective to (UTC, optional)',type:'datetime-local',value:p?.effective_to?.replace(' ','T')}];
    const bookFields=b=>[{name:'book_title',label:'Book title',value:b?.book_title,required:true},{name:'author',label:'Author',value:b?.author},{name:'publisher',label:'Publisher',value:b?.publisher},{name:'edition',label:'Edition',value:b?.edition},{name:'isbn',label:'ISBN',value:b?.isbn},{name:'notes',label:'Notes',type:'textarea',value:b?.notes}];
    function normalizeVariant(d){if(d.variant_metadata.trim()){try{d.variant_metadata=JSON.parse(d.variant_metadata);}catch{throw new Error('Variant metadata must be valid JSON.');}}else d.variant_metadata=null;d.sort_order=Number(d.sort_order);return d;}
    function normalizePrice(d){d.currency='PHP';d.effective_from=d.effective_from.replace('T',' ')+(d.effective_from.length===16?':00':'');d.effective_to=d.effective_to?d.effective_to.replace('T',' ')+(d.effective_to.length===16?':00':''):null;return d;}
    function scopeData(d){return {program_code:d.program_code||null,year_level:d.year_level||null};}

    async function command(name,el){const item=state.detail?.item,variantId=Number(el?.dataset.id||0);if(name==='select-item')return selectItem(el.dataset.id);
        if(name==='create-item')return editor('Create Draft item',itemFields(),d=>runMutation('create_item',{},d,'Draft item created.').then(r=>{state.selectedId=r.result.sale_item_id;}));
        if(name==='edit-item')return editor('Edit Draft item',itemFields(item),d=>runMutation('update_item',{item_id:item.sale_item_id},d,'Draft item updated.'));
        if(name==='add-variant')return editor('Create Draft variant',variantFields(),d=>runMutation('create_variant',{item_id:item.sale_item_id},normalizeVariant(d),'Draft variant created.'));
        if(name==='add-standard')return confirmAction('Create STANDARD variant','Create an explicit Draft STANDARD variant for this item?',()=>runMutation('create_standard_variant',{item_id:item.sale_item_id},{},'STANDARD variant created.'));
        const variant=(state.detail?.variants||[]).find(v=>Number(v.sale_variant_id)===variantId);
        if(name==='edit-variant')return editor('Edit variant',variantFields(variant),d=>runMutation('update_variant',{variant_id:variantId},normalizeVariant(d),'Variant updated.'));
        if(name==='deactivate-variant'||name==='archive-variant')return confirmAction(name==='deactivate-variant'?'Deactivate variant':'Archive variant','The server will verify lifecycle permissions and Active-item consistency.',()=>runMutation(name.replace('-','_'),{variant_id:variantId},{},`Variant ${name==='deactivate-variant'?'deactivated':'archived'}.`));
        if(name==='add-price'||name==='replace-price')return editor(name==='add-price'?'Create Draft price':'Create successor price',priceFields(),d=>runMutation(name==='add-price'?'create_price':'replace_active_price',{variant_id:variantId},normalizePrice(d),name==='add-price'?'Draft price created.':'Successor price activated.'));
        const priceId=Number(el?.dataset.id||0),price=(state.detail?.variants||[]).flatMap(v=>v.prices||[]).find(p=>Number(p.sale_variant_price_id)===priceId);
        if(name==='edit-price')return editor('Edit Draft price',priceFields(price),d=>runMutation('update_price',{price_id:priceId},normalizePrice(d),'Draft price updated.'));
        if(name==='activate-price')return confirmAction('Activate price','Activate this effective-dated price?',()=>runMutation('activate_price',{price_id:priceId},{},'Price activated.'));
        if(name==='retire-price')return editor('Retire Active price',[{name:'effective_to',label:'Close at (UTC)',type:'datetime-local',required:true}],d=>runMutation('retire_price',{price_id:priceId},{effective_to:normalizePrice({effective_from:d.effective_to,effective_to:''}).effective_from},'Price retired.'));
        if(name==='cancel-price')return confirmAction('Cancel price','The server will validate the actual price state and required permission.',()=>runMutation(el.dataset.status==='Draft'?'cancel_draft_price':'cancel_future_active_price',{price_id:priceId},{},'Price cancelled.'));
        if(name==='change-mode'){const mode=item.applicability_mode==='ALL'?'RESTRICTED':'ALL';return confirmAction(`Set ${mode}`,mode==='ALL'?'Active assignments will be deactivated and preserved as history.':'At least one Active assignment will be required before activation.',()=>runMutation('change_applicability_mode',{item_id:item.sale_item_id},{mode},`Applicability changed to ${mode}.`));}
        if(name==='add-scope')return editor('Add applicability scope',[{name:'program_code',label:'Program code (optional)'},{name:'year_level',label:'Year level (optional)',type:'select',options:[{value:'',label:'Any year'},{value:'1',label:'1'},{value:'2',label:'2'},{value:'3',label:'3'},{value:'4',label:'4'}]}],d=>runMutation('add_applicability',{item_id:item.sale_item_id},scopeData(d),'Applicability scope added.'));
        if(name==='deactivate-scope')return confirmAction('Deactivate assignment','Keep this assignment as Inactive history?',()=>runMutation('deactivate_applicability',{assignment_id:variantId},{},'Applicability assignment deactivated.'));
        if(name==='replace-scopes')return editor('Replace Active applicability set',[{name:'scopes',label:'Scopes',type:'textarea',rows:6,placeholder:'BSIT|1\nBSCS|2',help:'One scope per line: PROGRAM|YEAR. Leave either side blank for Any.'}],d=>{const scopes=d.scopes.split(/\r?\n/).filter(x=>x.trim()).map(line=>{const [program_code='',year_level='']=line.split('|');return scopeData({program_code:program_code.trim(),year_level:year_level.trim()});});return runMutation('replace_applicability',{item_id:item.sale_item_id},{scopes},'Active applicability set replaced.');});
        if(name==='add-book'||name==='edit-book')return editor(name==='add-book'?'Add Book metadata':'Edit Book metadata',bookFields(state.book),d=>runMutation(name==='add-book'?'create_book_details':'update_book_details',{item_id:item.sale_item_id},d,'Book metadata saved.'));
        if(['activate-item','deactivate-item','archive-item'].includes(name))return confirmAction(name.replace('-',' '),`The server will validate all prerequisites and lifecycle rules.`,()=>runMutation(name.replace('-','_'),{item_id:item.sale_item_id},{},`Item ${name.split('-')[0]}d.`));
    }
    document.addEventListener('click',e=>{const el=e.target.closest('[data-command]');if(el)command(el.dataset.command,el).catch(()=>{});});
    byId('catalogEditorSave').addEventListener('click',async()=>{const b=byId('catalogEditorSave');b.disabled=true;try{await state.editorSave?.();}catch(e){if(!e.payload&&!e.uncertain)notify(e.message,'danger');}finally{b.disabled=false;}});
    byId('catalogConfirmRun').addEventListener('click',async()=>{const b=byId('catalogConfirmRun');b.disabled=true;try{await state.confirmRun?.();}catch{}finally{b.disabled=false;}});
    byId('catalogRetry').addEventListener('click',async()=>{if(!state.retry)return;try{const result=await state.retry();state.retry=null;const pending=result.audit_status==='pending';notify(pending?'The catalog change was already committed; audit synchronization is pending.':'The catalog change was committed successfully.',pending?'warning':'success');await reload(state.selectedId);}catch(e){notify(e.message,'danger',e.uncertain?e.retry:null);}});
    ['catalogSearch','catalogStatus','catalogType'].forEach(id=>byId(id).addEventListener(id==='catalogSearch'?'input':'change',renderList));
    loadBase().catch(e=>{byId('catalogListLoading').classList.add('d-none');notify(e.message,'danger');});
})();
