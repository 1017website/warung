const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../../resources/views/pos/index.blade.php'),'utf8');
const payloadFunction=source.match(/^function orderPayload\(\).*$/m)[0];
const start=source.indexOf('async function submitOrder(');
const submitFunction=source.slice(start,source.indexOf('\n}',start)+2);

for(const [name,payment,deposit,cash,expected,blocked] of [
    ['empty cash','cash',0,'',0,true],
    ['insufficient cash','cash',0,'1.000',1000,true],
    ['exact cash','cash',0,'25.000',25000,false],
    ['cash change','cash',0,'30.000',30000,false],
    ['split insufficient','cash',5000,'19.000',24000,true],
    ['split exact','cash',5000,'20.000',25000,false],
    ['split change','cash',5000,'25.000',30000,false],
    ['deposit remainder empty','deposit',5000,'',5000,true],
    ['deposit remainder cash','deposit',5000,'20.000',25000,false],
    ['full deposit','deposit',25000,'',25000,false],
    ['transfer exact','transfer',0,'',25000,false],
]) {
    test(`actual received payment: ${name}`,async()=>{
        const elements={
            'deposit-amount':{value:String(deposit)}, 'paid-amount':{value:cash},
            'member-id':{value:deposit?'7':''},'payment-provider':{value:'BCA'},
            'replacement-mode':{checked:false},'approval-pin':{value:''},
            'checkout-btn':{disabled:false},'hold-btn':{disabled:false},
        };
        const calls=[];
        const ctx=vm.createContext({payment,serviceType:'takeaway',pendingId:null,
            cart:new Map([['p1',{id:1,qty:1,custom:false}]]),
            totals:()=>({total:25000,type:'amount',value:0}),
            moneyValue:input=>Number(input.value.replace(/\D/g,'')||0),
            document:{getElementById:id=>elements[id],querySelector:()=>({content:'token'})},
            alert:message=>calls.push(['alert',message]),window:{open:()=>{calls.push(['popup']);return null;}},
            fetch:async(url,options)=>{calls.push(['request',JSON.parse(options.body)]);return {ok:true,json:async()=>({})};},
            location:{reload:()=>calls.push(['reload'])},
        });
        vm.runInContext(payloadFunction+'\n'+submitFunction,ctx);
        const actual=ctx.orderPayload();
        assert.equal(actual.payments.reduce((n,p)=>n+p.amount,0),expected);
        await ctx.submitOrder('/checkout');
        assert.equal(calls.some(c=>c[0]==='request'),!blocked);
        if(blocked){assert.equal(calls.some(c=>c[0]==='popup'),false);assert.match(calls[0][1],/pembayaran kurang/);}
        // Holding an unpaid order must remain available even when cash is missing.
        if(name==='empty cash') {calls.length=0;await ctx.submitOrder('/hold',true);assert.equal(calls.some(c=>c[0]==='request'),true);}
    });
}
