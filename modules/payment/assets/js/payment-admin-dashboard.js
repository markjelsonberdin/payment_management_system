(() => {
  'use strict';
  const byId = id => document.getElementById(id);
  const charts = {};
  const money = value => value == null || !Number.isFinite(Number(value)) ? 'Unavailable' : 'PHP ' + Number(value).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
  const count = value => value == null || !Number.isFinite(Number(value)) ? 'Unavailable' : Number(value).toLocaleString('en-PH');
  const text = (id, value) => { const el=byId(id); if(el) el.textContent=value; };
  const state = (data,key) => data?.section_status?.[key] || 'error';
  const unavailable = key => ['error','partial'].includes(key);
  const row = (cells, className='') => { const tr=document.createElement('tr'); if(className)tr.className=className; cells.forEach(v=>{const td=document.createElement('td'); if(v instanceof Node)td.append(v);else td.textContent=String(v??'—');tr.append(td);});return tr; };
  const badge = (label, cls) => { const el=document.createElement('span');el.className='badge '+cls;el.textContent=label;return el; };
  const setKpi = (amountId,countId,amount,n,stateName) => {
    text(amountId, unavailable(stateName) ? 'Unavailable' : money(amount));
    text(countId, unavailable(stateName) ? 'Data unavailable' : count(n)+' verified payments');
  };
  const destroyChart = id => { if(charts[id]){charts[id].destroy();delete charts[id];} };
  const renderChart = (id, config) => { const canvas=byId(id);destroyChart(id);if(canvas&&typeof Chart!=='undefined')charts[id]=new Chart(canvas,config); };

  function renderTrend(data) {
    if(state(data,'trend')==='error'){text('trendState','Trend data unavailable.');destroyChart('paymentTrendChart');return;}
    const trend=data.trend||{}; const sets=[{label:'Current period',data:trend.current||[],borderColor:'#2563eb',backgroundColor:'rgba(37,99,235,.12)',fill:true,tension:.25}];
    if(trend.comparison_label)sets.push({label:'Comparable prior period',data:trend.prior||[],borderColor:'#94a3b8',borderDash:[5,4],tension:.25});
    renderChart('paymentTrendChart',{type:'line',data:{labels:trend.labels||[],datasets:sets},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{position:'bottom'}},scales:{y:{beginAtZero:true,ticks:{callback:v=>'PHP '+Number(v).toLocaleString('en-PH')}}}}});
    text('trendState',trend.comparison_label ? 'Compared with the same elapsed portion of the prior period.' : 'No prior-period comparison for this range.');
  }

  function renderMix(data) {
    if(state(data,'kpis')==='error'){destroyChart('collectionMixChart');text('concernAmountSummary','Collection mix unavailable.');return;}
    const k=data.kpis||{};renderChart('collectionMixChart',{type:'doughnut',data:{labels:['Cash','Live Online','Bank / Payment Concern'],datasets:[{data:[Number(k.cash_amount)||0,Number(k.live_online_amount)||0,Number(k.payment_concern_amount)||0],backgroundColor:['#2563eb','#10b981','#f59e0b'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'},tooltip:{callbacks:{label:c=>c.label+': '+money(c.raw)}}}}});
    text('concernAmountSummary','Bank / Payment Concern: '+money(k.payment_concern_amount)+' · '+count(k.payment_concern_count)+' payments');
  }

  function renderChannels(data, providerData = null) {
    const host=byId('receivingChannels');host.replaceChildren();
    if(state(data,'configuration')==='error'||!data.configuration){host.textContent='Receiving-channel configuration unavailable.';text('activeReceivingCount','Unavailable');text('channelState','Channel configuration unavailable.');destroyChart('channelChart');return;}
    const cfg=data.configuration; text('gatewayEnvironment',cfg.gateway_mode==='live'?'LIVE environment':'TEST environment · NON-FINANCIAL');
    const providerItems=providerData?.status==='ok'?(providerData.items||[]):null;
    const readyCount=providerItems?providerItems.filter(channel=>channel.available_to_students).length:null;
    const configuredAllowed=(cfg.channels||[]).filter(channel=>channel.available_by_config).length;
    text('activeReceivingCount',providerItems?readyCount+' channel'+(readyCount===1?'':'s')+' available to students':configuredAllowed+' configured channel'+(configuredAllowed===1?'':'s')+' allowed by checkout policy; provider status not checked.');
    (cfg.channels||[]).forEach(channel=>{
      const line=document.createElement('div');line.className='d-flex justify-content-between align-items-center border rounded p-2 gap-2';
      const label=document.createElement('span');label.className='fw-semibold';label.textContent=channel.name;line.append(label);
      const provider=providerItems?.find(item=>item.code===channel.code);
      let status=channel.configured?'Configured':'Disabled';let cls=channel.configured?'text-bg-secondary':'text-bg-secondary';
      if(!channel.policy_allowed){status='Disabled by Live checkout policy';cls='text-bg-light text-dark border';}
      else if(providerItems&&provider?.available_to_students){status='Available to students';cls='text-bg-success';}
      else if(providerItems&&channel.configured&&provider&&!provider.provider_active){status='Not active in provider';cls='text-bg-warning';}
      else if(providerItems&&channel.configured&&!provider?.provider_active){status='Provider status unavailable';cls='text-bg-warning';}
      line.append(badge(status,cls));host.append(line);
    });
    const dataRows=(data.channel_breakdown||[]).filter(x=>x&&x.label);
    if(state(data,'channel_breakdown')==='error'){text('channelState','Payment-channel totals unavailable.');destroyChart('channelChart');return;}
    renderChart('channelChart',{type:'bar',data:{labels:dataRows.map(x=>x.label),datasets:[{label:'Official allocated collections',data:dataRows.map(x=>Number(x.amount)||0),backgroundColor:'#2563eb',borderRadius:4}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>money(c.raw)}}},scales:{x:{beginAtZero:true,ticks:{callback:v=>'PHP '+Number(v).toLocaleString('en-PH')}}}}});
    text('channelState',dataRows.length?'Based on payment_channel, not inferred sender wallet or gateway.':'No channel allocations in this period.');
  }

  function renderStatuses(data) {
    const body=byId('onlineStatusRows');body.replaceChildren();
    if(state(data,'online_status')==='error'){body.append(row(['Online status data unavailable','',''],''));}
    else if(!(data.online_status||[]).length)body.append(row(['No attempts in selected period','—','0'],'text-muted'));
    else data.online_status.forEach(item=>body.append(row([item.environment==='unknown'?'Unknown environment':item.environment,item.status,count(item.count)])));
    const failed=state(data,'failed_expired');const summary=byId('failedExpiredSummary');summary.replaceChildren();
    if(failed==='error'){summary.textContent='Status summary unavailable.';return;}
    const entries=data.failed_expired||[];
    if(!entries.length){summary.append(badge('No failed / expired LIVE attempts','text-bg-success'));return;}
    entries.forEach(item=>{const span=document.createElement('span');span.className='me-2 mb-2 d-inline-block';span.append(badge(item.status+' · '+count(item.count),item.status==='Failed'?'text-bg-warning':'text-bg-secondary'));summary.append(span);});
  }

  function renderAttention(data, gateway) {
    const host=byId('attentionItems');host.replaceChildren();const items=[];
    if(state(data,'needs_attention')==='error')items.push({label:'Integrity checks unavailable',count:null,severity:'warning'});else items.push(...(data.needs_attention||[]));
    if(gateway?.gateway?.status && /NOT READY|INACTIVE/.test(gateway.gateway.status))items.push({label:'Gateway readiness: '+gateway.gateway.status,count:null,severity:gateway.configuration?.gateway_mode==='live'?'critical':'warning'});
    if(data.configuration?.gateway_mode==='live' && gateway?.channels?.status==='ok' && !(gateway.channels.items||[]).some(channel=>channel.available_to_students))items.push({label:'No Live receiving channel is available to students under the QRPh-only checkout policy',count:null,severity:'critical'});
    if(data.configuration?.gateway_mode==='live' && gateway?.channels?.status==='not_checked')items.push({label:'Live receiving-channel provider availability could not be confirmed',count:null,severity:'warning'});
    if(gateway?.unavailable)items.push({label:'Gateway readiness could not be checked',count:null,severity:'warning'});
    const badgeEl=byId('attentionBadge');
    if(!items.length){badgeEl.className='badge text-bg-success';badgeEl.textContent='Clear';host.textContent='No supported actionable findings right now.';return;}
    const critical=items.some(x=>x.severity==='critical');badgeEl.className='badge '+(critical?'text-bg-danger':'text-bg-warning');badgeEl.textContent=critical?'Action required':'Review';
    items.forEach(item=>{const alert=document.createElement('div');alert.className='alert '+(item.severity==='critical'?'alert-danger':'alert-warning')+' py-2 mb-0';alert.textContent=item.label+(item.count==null?'':' · '+count(item.count));host.append(alert);});
  }

  function renderRecent(data) {
    const body=byId('recentActivityRows');body.replaceChildren();text('recentScope',data.scope?.timezone+' · '+(data.scope?.start_at||'')+' to < '+(data.scope?.end_exclusive||''));
    if(state(data,'recent_activity')==='error'){body.append(row(['Recent activity unavailable','','','','','','','']));return;}
    const items=data.recent_activity||[];if(!items.length){body.append(row(['No payment activity in this period','','','','','','',''],'text-muted'));return;}
    items.forEach(item=>{const timestamp=item.payment_status==='Verified'?item.verified_at:item.created_at;const dt=timestamp?new Date(String(timestamp).replace(' ','T')+'+08:00').toLocaleString('en-PH'):'—';const student=item.full_name?item.full_name+(item.student_number?' ('+item.student_number+')':''):'—';const ref=item.receipt_number||item.reference_number||('Payment #'+item.payment_id);const env=item.transaction_type==='Online'?(item.gateway_environment||'unknown'):'—';const applied=item.payment_status==='Verified'?money(item.applied_amount):'—';body.append(row([dt,ref,student,item.transaction_type+' / '+item.payment_channel,env,item.payment_status,applied,money(item.attempted_amount)]));});
  }

  async function loadGateway(data) {
    try { const response=await fetch(window.PAYMENT_ADMIN_GATEWAY_STATUS_API,{credentials:'same-origin',headers:{Accept:'application/json'}});if(!response.ok)throw new Error('status');const result=await response.json();result.configuration=data.configuration;
      const status=result.gateway?.status||'Status unavailable';text('gatewayReadiness','Gateway: '+status+' · API '+(result.api?.connected?'connected':result.api?.configured?'not connected':'not configured')+' · Webhook '+(result.webhook?.status||'unknown')+' · Checked '+(result.checked_at||'—'));return result;
    } catch(e) { text('gatewayReadiness','Protected gateway status is unavailable; no configuration details were exposed.');return {unavailable:true,configuration:data.configuration}; }
  }

  async function load() {
    const period=byId('dashboardPeriod').value;const notice=byId('dashboardNotice');notice.className='alert alert-info';notice.textContent='Loading '+period+' payment data…';
    try {
      const url=new URL(window.PAYMENT_ADMIN_DASHBOARD_API,window.location.origin);url.searchParams.set('period',period);
      const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}});const data=await response.json();if(!response.ok||data.error)throw new Error(data.message||'Dashboard data is unavailable.');
      const k=data.kpis||{};setKpi('totalSuccessfulAmount','totalSuccessfulCount',k.total_successful_amount,k.total_successful_count,state(data,'kpis'));setKpi('cashAmount','cashCount',k.cash_amount,k.cash_count,state(data,'kpis'));setKpi('liveOnlineAmount','liveOnlineCount',k.live_online_amount,k.live_online_count,state(data,'kpis'));
      text('pendingLiveCount',state(data,'pending_queue')==='error'?'Unavailable':count(data.pending_queue?.count));
      if(state(data,'school_sales')==='error'){text('activeSaleCategories','Unavailable');text('activeSaleItems','Unavailable');}else{text('activeSaleCategories',count(data.school_sales?.active_categories));text('activeSaleItems',count(data.school_sales?.active_items));}
      if(state(data,'configuration')==='error')text('gatewayEnvironment','Environment unavailable');
      if(data.trend?.comparison_label && Array.isArray(data.trend.prior) && data.trend.prior.length < (data.trend.labels||[]).length)data.trend.prior=data.trend.prior.concat(Array((data.trend.labels||[]).length-data.trend.prior.length).fill(0));
      renderTrend(data);renderMix(data);renderChannels(data);renderStatuses(data);renderRecent(data);
      const failed=Object.values(data.section_status||{}).includes('error');notice.className='alert '+(failed?'alert-warning':'alert-success');notice.textContent=(failed?'Some sections are unavailable; other dashboard sections remain available. ':'Updated '+new Date(data.generated_at).toLocaleString('en-PH')+' · ')+(data.scope?.timezone||'Asia/Manila')+' · '+(data.scope?.start_at||'')+' ≤ activity < '+(data.scope?.end_exclusive||'');
      const gateway=await loadGateway(data);renderChannels(data,gateway?.channels);renderAttention(data,gateway);
    } catch(error) { notice.className='alert alert-danger';notice.textContent=error.message||'Payment operations data is temporarily unavailable.';['totalSuccessfulAmount','cashAmount','liveOnlineAmount','pendingLiveCount','activeSaleCategories','activeSaleItems','gatewayEnvironment','activeReceivingCount'].forEach(id=>text(id,'Unavailable'));text('totalSuccessfulCount','Data unavailable');text('cashCount','Data unavailable');text('liveOnlineCount','Data unavailable');text('gatewayReadiness','Unavailable');['paymentTrendChart','collectionMixChart','channelChart'].forEach(destroyChart);byId('onlineStatusRows').replaceChildren(row(['Online status unavailable','','']));byId('recentActivityRows').replaceChildren(row(['Recent activity unavailable','','','','','','','']));byId('receivingChannels').textContent='Receiving-channel status unavailable.';byId('failedExpiredSummary').textContent='Status summary unavailable.';byId('attentionItems').textContent='Attention checks unavailable.'; }
  }

  byId('dashboardPeriod').addEventListener('change',load);byId('refreshDashboard').addEventListener('click',load);load();
})();
