(() => {
 const nativeFetch = window.fetch.bind(window);
 const money = n => n == null ? 'N/A' : 'PHP ' + Number(n).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2});
 window.fetch = async (...args) => {
  const response = await nativeFetch(...args);
  if (String(args[0]).startsWith(window.ACCOUNTING_DASHBOARD_API)) {
   response.clone().json().then(data => {
    window.setTimeout(() => {
     const host = document.getElementById('dashKpis');
     if (!host || !data.kpis) return;
     let card = document.getElementById('liveOnlineCollectionsKpi');
     if (!card) {
      card = document.createElement('div');
      card.id = 'liveOnlineCollectionsKpi';
      card.className = 'col-12 col-sm-6 col-xl card h-100 p-3';
      host.appendChild(card);
     }
     card.replaceChildren();
     const label = document.createElement('div');
     label.className = 'small text-muted';
     label.textContent = 'Live Online Collections';
     const amount = document.createElement('div');
     amount.className = 'h4';
     amount.textContent = money(data.kpis.live_online_collections);
     const note = document.createElement('small');
     note.textContent = 'Live gateway allocations';
     card.append(label, amount, note);
    }, 0);
   }).catch(() => {});
  }
  return response;
 };
})();
