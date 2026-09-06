const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const template=fs.readFileSync(path.join(__dirname,'../../resources/views/purchases/index.blade.php'),'utf8');
const script=template.split('<script>')[1].split('</script>')[0];

test('opening a purchase correction preserves decimal database prices and DP',()=>{
    let click;
    const data={supplier_name:'Supplier',unit_cost:'200000.00',dp_amount:'1000000.00',quantity:'10.000'};
    const elements=Object.fromEntries(Object.keys(data).map(key=>[key,{value:''}]));
    const form={elements};
    vm.runInNewContext(script,{
        document:{querySelectorAll:()=>[{dataset:{url:'/pembelian/1',purchase:JSON.stringify(data)},addEventListener:(_,callback)=>{click=callback;}}],getElementById:()=>form},
        setMoneyInputValue:(input,value)=>{input.value=String(value).replace(/\D/g,'').replace(/\B(?=(\d{3})+(?!\d))/g,'.');},
        openModal:()=>{},
    });
    click();
    assert.equal(elements.unit_cost.value,'200.000');
    assert.equal(elements.dp_amount.value,'1.000.000');
    assert.equal(elements.quantity.value,'10.000');
    assert.equal(form.action,'/pembelian/1');
});
