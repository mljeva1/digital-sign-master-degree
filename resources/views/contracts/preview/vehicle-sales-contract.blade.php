<!DOCTYPE html>
<!-- Created by pdf2htmlEX (https://github.com/pdf2htmlEX/pdf2htmlEX) -->
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8"/>
<meta name="generator" content="pdf2htmlEX"/>
<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1"/>
<style type="text/css">
/*! 
 * Base CSS for pdf2htmlEX
 * Copyright 2012,2013 Lu Wang <coolwanglu@gmail.com> 
 * https://github.com/pdf2htmlEX/pdf2htmlEX/blob/master/share/LICENSE
 */#sidebar{position:absolute;top:0;left:0;bottom:0;width:250px;padding:0;margin:0;overflow:auto}#page-container{position:absolute;top:0;left:0;margin:0;padding:0;border:0}@media screen{#sidebar.opened+#page-container{left:250px}#page-container{bottom:0;right:0;overflow:auto}.loading-indicator{display:none}.loading-indicator.active{display:block;position:absolute;width:64px;height:64px;top:50%;left:50%;margin-top:-32px;margin-left:-32px}.loading-indicator img{position:absolute;top:0;left:0;bottom:0;right:0}}@media print{@page{margin:0}html{margin:0}body{margin:0;-webkit-print-color-adjust:exact}#sidebar{display:none}#page-container{width:auto;height:auto;overflow:visible;background-color:transparent}.d{display:none}}.pf{position:relative;background-color:white;overflow:hidden;margin:0;border:0}.pc{position:absolute;border:0;padding:0;margin:0;top:0;left:0;width:100%;height:100%;overflow:hidden;display:block;transform-origin:0 0;-ms-transform-origin:0 0;-webkit-transform-origin:0 0}.pc.opened{display:block}.bf{position:absolute;border:0;margin:0;top:0;bottom:0;width:100%;height:100%;-ms-user-select:none;-moz-user-select:none;-webkit-user-select:none;user-select:none}.bi{position:absolute;border:0;margin:0;-ms-user-select:none;-moz-user-select:none;-webkit-user-select:none;user-select:none}@media print{.pf{margin:0;box-shadow:none;page-break-after:always;page-break-inside:avoid}@-moz-document url-prefix(){.pf{overflow:visible;border:1px solid #fff}.pc{overflow:visible}}}.c{position:absolute;border:0;padding:0;margin:0;overflow:hidden;display:block}.t{position:absolute;white-space:pre;font-size:1px;transform-origin:0 100%;-ms-transform-origin:0 100%;-webkit-transform-origin:0 100%;unicode-bidi:bidi-override;-moz-font-feature-settings:"liga" 0}.t:after{content:''}.t:before{content:'';display:inline-block}.t span{position:relative;unicode-bidi:bidi-override}._{display:inline-block;color:transparent;z-index:-1}::selection{background:rgba(127,255,255,0.4)}::-moz-selection{background:rgba(127,255,255,0.4)}.pi{display:none}.d{position:absolute;transform-origin:0 100%;-ms-transform-origin:0 100%;-webkit-transform-origin:0 100%}.it{border:0;background-color:rgba(255,255,255,0.0)}.ir:hover{cursor:pointer}</style>
<style type="text/css">
/*! 
 * Fancy styles for pdf2htmlEX
 * Copyright 2012,2013 Lu Wang <coolwanglu@gmail.com> 
 * https://github.com/pdf2htmlEX/pdf2htmlEX/blob/master/share/LICENSE
 */@keyframes fadein{from{opacity:0}to{opacity:1}}@-webkit-keyframes fadein{from{opacity:0}to{opacity:1}}@keyframes swing{0{transform:rotate(0)}10%{transform:rotate(0)}90%{transform:rotate(720deg)}100%{transform:rotate(720deg)}}@-webkit-keyframes swing{0{-webkit-transform:rotate(0)}10%{-webkit-transform:rotate(0)}90%{-webkit-transform:rotate(720deg)}100%{-webkit-transform:rotate(720deg)}}@media screen{#sidebar{background-color:#2f3236;background-image:url("data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI0IiBoZWlnaHQ9IjQiPgo8cmVjdCB3aWR0aD0iNCIgaGVpZ2h0PSI0IiBmaWxsPSIjNDAzYzNmIj48L3JlY3Q+CjxwYXRoIGQ9Ik0wIDBMNCA0Wk00IDBMMCA0WiIgc3Ryb2tlLXdpZHRoPSIxIiBzdHJva2U9IiMxZTI5MmQiPjwvcGF0aD4KPC9zdmc+")}#outline{font-family:Georgia,Times,"Times New Roman",serif;font-size:13px;margin:2em 1em}#outline ul{padding:0}#outline li{list-style-type:none;margin:1em 0}#outline li>ul{margin-left:1em}#outline a,#outline a:visited,#outline a:hover,#outline a:active{line-height:1.2;color:#e8e8e8;text-overflow:ellipsis;white-space:nowrap;text-decoration:none;display:block;overflow:hidden;outline:0}#outline a:hover{color:#0cf}#page-container{background-color:#9e9e9e;background-image:url("data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI1IiBoZWlnaHQ9IjUiPgo8cmVjdCB3aWR0aD0iNSIgaGVpZ2h0PSI1IiBmaWxsPSIjOWU5ZTllIj48L3JlY3Q+CjxwYXRoIGQ9Ik0wIDVMNSAwWk02IDRMNCA2Wk0tMSAxTDEgLTFaIiBzdHJva2U9IiM4ODgiIHN0cm9rZS13aWR0aD0iMSI+PC9wYXRoPgo8L3N2Zz4=");-webkit-transition:left 500ms;transition:left 500ms}.pf{margin:13px auto;box-shadow:1px 1px 3px 1px #333;border-collapse:separate}.pc.opened{-webkit-animation:fadein 100ms;animation:fadein 100ms}.loading-indicator.active{-webkit-animation:swing 1.5s ease-in-out .01s infinite alternate none;animation:swing 1.5s ease-in-out .01s infinite alternate none}.checked{background:no-repeat url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABYAAAAWCAYAAADEtGw7AAAABmJLR0QA/wD/AP+gvaeTAAAACXBIWXMAAAsTAAALEwEAmpwYAAAAB3RJTUUH3goQDSYgDiGofgAAAslJREFUOMvtlM9LFGEYx7/vvOPM6ywuuyPFihWFBUsdNnA6KLIh+QPx4KWExULdHQ/9A9EfUodYmATDYg/iRewQzklFWxcEBcGgEplDkDtI6sw4PzrIbrOuedBb9MALD7zv+3m+z4/3Bf7bZS2bzQIAcrmcMDExcTeXy10DAFVVAQDksgFUVZ1ljD3yfd+0LOuFpmnvVVW9GHhkZAQcxwkNDQ2FSCQyRMgJxnVdy7KstKZpn7nwha6urqqfTqfPBAJAuVymlNLXoigOhfd5nmeiKL5TVTV+lmIKwAOA7u5u6Lped2BsbOwjY6yf4zgQQkAIAcedaPR9H67r3uYBQFEUFItFtLe332lpaVkUBOHK3t5eRtf1DwAwODiIubk5DA8PM8bYW1EU+wEgCIJqsCAIQAiB7/u253k2BQDDMJBKpa4mEon5eDx+UxAESJL0uK2t7XosFlvSdf0QAEmlUnlRFJ9Waho2Qghc1/U9z3uWz+eX+Wr+lL6SZfleEAQIggA8z6OpqSknimIvYyybSCReMsZ6TislhCAIAti2Dc/zejVNWwCAavN8339j27YbTg0AGGM3WltbP4WhlRWq6Q/btrs1TVsYHx+vNgqKoqBUKn2NRqPFxsbGJzzP05puUlpt0ukyOI6z7zjOwNTU1OLo6CgmJyf/gA3DgKIoWF1d/cIY24/FYgOU0pp0z/Ityzo8Pj5OTk9PbwHA+vp6zWghDC+VSiuRSOQgGo32UErJ38CO42wdHR09LBQK3zKZDDY2NupmFmF4R0cHVlZWlmRZ/iVJUn9FeWWcCCE4ODjYtG27Z2Zm5juAOmgdGAB2d3cBADs7O8uSJN2SZfl+WKlpmpumaT6Yn58vn/fs6XmbhmHMNjc3tzDGFI7jYJrm5vb29sDa2trPC/9aiqJUy5pOp4f6+vqeJ5PJBAB0dnZe/t8NBajx/z37Df5OGX8d13xzAAAAAElFTkSuQmCC)}}</style>
<style type="text/css">
.ff0{font-family:sans-serif;visibility:hidden;}
@font-face{font-family:ff1;src:url('data:application/font-woff;base64,d09GRgABAAAAACFEABAAAAAAN4gAAgABAAAAAAAAAAAAAAAAAAAAAAAAAABGRlRNAAAhKAAAABwAAAAckTDxIUdERUYAACEQAAAAFwAAABgAJQAAT1MvMgAAAeQAAABEAAAAVmMAaoVjbWFwAAACuAAAAOIAAAHQADJU92N2dCAAAAsYAAAAKwAAADQYgwcHZnBnbQAAA5wAAAbrAAAODGIu+3tnYXNwAAAhCAAAAAgAAAAIAAAAEGdseWYAAAuwAAAPawAAFuRT1apdaGVhZAAAAWwAAAA2AAAANhRVOmpoaGVhAAABpAAAACAAAAAkDHoFaWhtdHgAAAIoAAAAkAAAANLjVRLtbG9jYQAAC0QAAABsAAAAbJH8l+RtYXhwAAABxAAAACAAAAAgAUcBcm5hbWUAABscAAAFagAAC+Kliu8qcG9zdAAAIIgAAAB9AAAAqRFIOLxwcmVwAAAKiAAAAI8AAACnaEbInAABAAAAAhmZgyZRR18PPPUAHwgAAAAAAMhJaCYAAAAA5llvZ//g/k4GngX5AAAACAACAAAAAAAAeJxjYGRgYP35z4+BgV32/4P/J9nmMQBFUIAJAKgZBvAAAQAAADUANAACADMAAgACABQANgCNAAAAagDSAAIAAXicY2BkaWOcwMDKwMBqzDqTgYFRDkIzX2dIYxJiYGBiYGVmgAFWBiQQkOaaAqQUGOtYf/7zA0r+ZNwE5DOC5ACTQwpKeJxjesPgwgAETKuA2ByMu8F4FcNR1uMMxkAcAsSdrCEMnWzmDCFAuU6WYgZ5kBjLQ6DYKqDccYgcSA1UXBxIVwP5fEC2LVC9DZBuB9IBQDoETj9k6Aea18dk/v8BUAzMZpcFqnsIxiA17cyyYH0eQPdIAvn1QDYHCwNDENgMCPYA8dkYGM6C/MAQDgBabTEJeJy9j7tOAmEQhb8fFxZQEMRlvXBZV7kvPAAdlbEzlnYUxtZYWFjwSDQkQm9nSHgI34Nh1t1EotSc5MzJ/DM55x/ggIgVDCG+tDM/vcVUtUZBX/J4DBhyyz2PPPHCG+/GNjkzNhMR3fMIdD7ijgfGPPO6PZdvkKXyU7mQuXzITFrir1frWZwWJkGb/7CUSWytaTJkVQ851epQxo13zsCkiE9QTWhJ/PEx0aG/tsmtLhWJTTqTVf+jXP64UDwpaZBTdqOAEOcXl5Uqtbp35V/fNJot2p1uL9BBf8fH948N7mMlYgAAeJytV2tbG8cVntUNjAEDQtjNuu4oY1GXHckkcRxiKw7ZZVEcJanAuN11brtIuE2TXpLe6DW9X5Q/c1a0T51v+Wl5z8xKAQfcp89TPui8M/POnOucWUhoSeJ+FMZSdh+J+Z0uVe49iOiGS9fi5KEc3o+o0Eg/mxbTot9X+269TiImEaitkXBEkPhNcjTJ5GGTClrVVb1JRS0HR8XlmvADqgYySfyssBz4WaMYUCHYO5Q0qwCCdECl3uGoUCjgGKofXK7z7Gi+5viXJaDyR1WnijVFohcdxKMVp2AUljQVPaoFEeujlSDICa4cSPq8R6XVB6NrzlwQ9kOqhFGdio14960IZHcYSer1MLUJNm0w2ohjmVk2LLqGqXwkaZ3X15n5eS+SiMYwlTTTixLMSF6bYXST0c3ETeI4dhEtmg36JHYjEl0m1zF2u3SF0ZVu+mhB9JnxqCz243iQxuR4cZx7EMsB/FF+3KSylrCg1Ejh01TQi2hK+TStfGQAW5ImVUy4EQk5yKb2fcmL7K5rzedf8MI+ldfqWAzkUA6hK1svNxChnSjpueluHKm4HkvavBdhzeW45KY0aUrTucAbiYJN8zSGylcoF+WnVNh/SE4fCmhqrUnntGRr5+FWSexLPoE2k5gpyZaxdkaPzs2LIPTX6pPCOa9PFtKsPcXxYEIA1xMZDlXKSTXBFi4nhKQLI8dWIrUq3bIq5s7YTlexS7hfunZ807w2Dh3NzYpiCC2uqsdrKOILOisUQhqkW01a0KBKSReC1/gAAGSIFni0i9GCydciDlowQZGIQR+aaTFI5DCRtIiwNWlJd/eirDTYiq/S3IE6bFJVd3ei7j076dYxXzXzyzoTS8H9KFtaQgpTnxY9vnIoLT+7wD8L+CFnBbkoNnpRxuGDv/4QGYbahbW6wrYxdu06b8FN5pkYnnRgfwezJ5N1RgozIaoK8QpI3Bk5jmOyVdMiE4VwL6Il5cuQ5lF+c1Cc+DL5z6VLjlgUVeH7PkdgGWtOmi1Pe/Sp5z6NcK3Ax5rXpIs6c1heQrxZfk1nRZZP6azE0tVZmeVlnVVYfl1nUyyv6Gya5Td0do6lp9U4/lRJEGklW+S8w7elSfrY4spk8SO72Dy2uDpZ/NguSi3ognemn3Dq39ZV9vO4f3X4J2HX0/CPpYJ/LK/CP5YN+MdyFf6x/Cb8Y3kN/rH8FvxjuQb/WLa0bJuCva6h9lIiAzYhMCnFJWxxza5ruu7RddzHZ3AVOvKMbKp0Q3FjfyLDZe+fHac4m6+EXHH0zFpWdmphhKbIXj53LDxncW5o+byx/HmcZjnhV3Xi2p5qC8+LlX8J/tu6ozayG06Nfb2JeMCB0+3HZUk3mvSCbl1sN2njv1FR2H3QX0SKxEpDtmSHWwJCe3c47KgOekiEhw9dFy/ShuPUlhHhW+hdK3QRtBLaacPQslnh0/nAOxi2lJTtIc68fZImW/Y8qih/zJaUcE/Z3ImOSrIs3aPSavmp2OdOO4OmrcwOtZ1QJXj8uibc7eyrVAqSgaIyHlUsl4LUBU640z2+J4Vp6P9qGzlW0LDNL9ZMYLTgvFOUKNtTK2giSEYZBVf+yqk4kY1osBFF/Oad9EtdKIT2OBYSs+XVPBaqjTC9NFmiGbO+rTqslLN4ZxJCdsZGmsRe1JJtPOhsfT4p2a48FVRpYHT3+LeLTeJp1Z5nS3HJv3zMkmCcroQ/cB53eZziTfSPFkdxmy4GUc/FmyrbcStbd5Zxb185sbrr9k6s+qfufdKOQNMt70kKtzTd9oawjWsMTp1JRUJbtI4doXGZ63PVRj7FB5pvXecCVbg+Ldw8e/62zmbw1oy3/I8l3fl/VTH7xH2srdCqjtVLPc7t7KAB3/LGUXkVo9teXeVxyb2ZhOAuQlCz1x5fI7jh1RbdxC1/7Yz5Lo5zlqv0AvDrml6EeIOjGCLcchsP7zhab2ouaHoD8Nt6JMQ2QA/AYbCjR46Z2QUwM/eY0wHYYw6D+8xh8B3mMPiuPkIvDIAiIMegWB85du4BkJ17i3kOo7eZZ9A7zDPoXeYZ9B7rDAES1skgZZ0M9lkngz5zXgUYMIfBAXMYPGQOg+8Zu7aAvm/sYvS+sYvRD4xdjD4wdjH60NjF6IfGLkY/MnYx+jFi3J4k8CdmRJuAH1n4CuDHHHQz8jH6Kd7anPMzC5nzc8Nxcs4vsPmlyam/NCOz49BC3vErC5n+a5yTE35jIRN+ayETfgfuncl5vzcjQ//EQqb/wUKm/xE7c8KfLGTCny1kwl/AfXly3l/NyND/ZiHT/24h0/+BnTnhnxYyYWghEz7Vo/Pmy5Yq7qhUKIb4pwltMPY9mj6g4tXe4fixbn4BRJMBRAB4nGPw3sFwIihiIyNjX+QGxp0cDBwMyQUbGdidNokzMmiBGJt5OBi5ICwRNjCLw2kXswMDIwM3kM3ptIsBwt7JwMzA4LJRhbEjMGKDQ0cEiJ/islEDxN/BwQARYHCJlN6oDhLaxdHAwMji0JEcApMAgc18bIx8WjsY/7duYOndyMTgspk1hY3BxQUAq0Yq9QB4nGNgwASMqoyqDAcYDrA2MjCwnmGxYmD4F8467f8bINvv/5t/4QCLgwugAAAAACoAKgAqACoATgBoAKQA3AEkAVoBigHeAfgCMAJgAoACwgLyAzQDbAOwA9QECgQyBF4FEgV2BcAGKgZ6BvoHMAdcB5oHzAfmCEwImgjWCTwJkgnkCiYKcgqaCsgK1ArgCuwK+AsiC1ALcnichVgLdFRFtq1Tt+693fl09+1vQgLpTudDJnyUTggigZbHEx5J+AQQIkJIAEFA5PMgqCBERzAygKz1GBAXMDM6gIghOqC8EQQWA4wTEBQB+QgyoMjAiC4ZH5DuyjtV9yZBxllDFr+699Y5Z5999jlVhJJ+hNAJ6giiEJ38IppLCFEoUSYRCkBHEkqhkuG/YDAhuqYyfE0xVC2QHzFCRnbICPWjQZ4Fa/hkdcSdt/qxI/g9kKV8P9TD/SSBpEb9YmGx3IgAOGGQ4fEoemp+tlfTw72hMGyEoL7bAzt7DqyFYN95Owf331KKn5r74B9b8D+KuQ+BSvRK7CG8REcU3XRkKWTA/fworlJyAF/7iiXIeEpLGvxDRkUDGAGQSnxslKEjSqUKiuJUBqVFfYSI6AhMunu9IupmDJ/oDEOWhjQ05EFDgL8PQJB/CUH6HbTjX8ddkMqvCLsRQtSVai1adZKcaJjgBsDIZNzXUaYq1HI7OQlf0A0Dd0zNDyn4AyE76A7Af+ayJxfEryzgpxGrxyiJl2sJ6eth1dJ8mMzXqLV3FrLN/qzHeAGsegmxGd58XV2h/pqkkPujXVxOhWJuoJQoCq1Cm94yBuhAFULug0FAvO7kRF0lKZCi6v787KBH1Vg4SAwXCXVjAbULhDM1n9etpEHiZIBhfM+XfCtfBo/D8FvQow+Phfa98OejJz6DpKrDh6AWHoXR8N+H9vWfsuDWjR+aJe51GP9MjN9O3CQjmt6ScEcZuiSxd5JBoaxQmOnt8sFLWRjJEyRQkJMPRqSbW535Fj/0l/hB4DABFvNT189+cufDL2njGf7BVrWWv8rfuXQj1h80yQthi6GtRNI12gltK4wiY60co0XG1EoNVNWpCqYkkkRD/NL1NMEWX8j6XcfKY5/RG3FDGaHWXubrL/Nll03eCWx3IrbpJBot9nooVW1YFEBL0RZgZUwiqsqq0Iy3TEOYlSqM0acgzCl+tyspQRZKOqTrAupu3YsKQ4UhoxVvxL4V7uzZn/fkb9KJM/jaA3wzXwGzYQzcWMK/77Rr4bFT54//R8H+M/E7s5+HBTAWHoPZfGX51Omxazd4UwsOUI84tNSHo+zn66MOsgV/5DcZzdeVK/iNIbBjSEyKrBHkxxgQOgSxSlDHRzAcR3JSok0jSHvBGjUzJze7A0S6dS80RERejSadvZYL2e7qR0aN5ldonzveDz/Lf/KJudPppSux4nM/tuRqu8wVVkWCTrEoAEqFn44yNC95YSbJbXhUURWgo8tYa1gYbBTX6vlpyKXPwqj4RrqULnkxztXaeC1dGN8aO27uz36H+6uSdTL9WNHjFKDUScXOKlENg+kpEgnMOuTQH9TapvFWrvF7LYTfB0huNMuTwBTMb6n4GsuWgdLiX4AEfIYvoCF7Q0ZB9yKqK+FE8PvEnp4MKIKQoaaBvX8m/5RPTeJ3YGnMf18fUKBOeaR9z3P8hymxbxU31HxTEtui1saul+7+q/KgTImFUTH6kEyyoiGb0KOfQSiZJHsNr4mQaRdyMBGGcim+1o0Gh9Kn3KCx4vXjYvswvj+unq9EpAEq+XwR+ZyEamHVplAGr6zNKiKznZWV1VabFlOxPGk4kyJTRYVerOe7P+fb+UtQA4PxZx4/fmr/wVPn9hw8SQ+d4+++A0tgOAyD+XwRf+cyKLz562/4PzDfrfrgkDzwkHA0aOnjJCFYlVIkze4QQl0Iq1ip4HWA0AgDBUotyEPl7dZddcz/egP/HT9D58XBxT/nd/hReOCZXyr7Xzo5h7vU2qtnzvOip1vtrZOY+kgkel8SEBWJrqJVFa0yZlnVWqXZ6zacAuSQUAoBsmXch43KDpYHbCj/X74RJXpvDNybV8ACvpLH+GJ4fv4iGohfVWtPN/7Pqcx4g/JJIx83Q+Q2gPxslH0hFO0g6g1a4m1JrNkQRGJFe8HK8imMn+Y92Xa2rmk8W3f5stjnWczhQFm3yBFFkgO3+UmxJthQd1qKNUhSMQKv3yxXTCc9zr/gjZC7Ye36LZDLd3ghHWzKzNjGTW/veFMZElvPb/LTJh8diN3DaCuBpEVTUOFa6Wj67DdcooPnASATA33wL+UhfiJ+CwoglNbVF4Eg3I8crxn17iPblTrcrxgx2Ch7Q140R0cEKOCUUUrasGjrEW7D7RI8zINCEFhAiG1sWk1vx4cpN+O6kvmJ8tGVI7GIzHGv5utsERtEcrD7flrSkIG9PjErjap6OmablqZFHVlg09NAtYkFpbSipMGN7+QSIXvKLPRMV0F/Al2Qah4os2HTIFWanUpM08wtO5qvw6J/+360a+urqo3YVCI/sf3kE2KztX1RURFNBXJfl7zczGC7FI/hdGD3UEkO5CSYgltY0L0PFBbkYL/Qs3uj9mLli5wW+RxKGPU4rHl0BxYoLuEsBXXrGs4euzpw+KD/svOzadcaj5zPuy/YIbVjx84dpkxM0OZWvFJdnt//wb5P9va+9drmBsqKpkzqX+5Y//pf/sjnjv5PbY2WoLHJE09QO4rAgF5lJQMW9keclyDOy9TDxE9CpOI9Q6OojDhbpSI4aRiZS3Rc2QQxPAEIgIVHxr1PsdbMV0zRqYjaUXc8fowuPR9cVIaE9RaSnNUj3VF3QEYvQ2TLTh2avbkztav8OxukMFbZtOcIPzdt5qyaObMu0BAy+NSEseFnjDFr2Qle3XAMGf/jznf3bN+6V/J6CMZRhHxJIQ9GexCq4FymYqqYorLn0D8pigHhLmaopaxQ/FyYFbs5PekyLUIdMRXm3GS4sMSKVAel5T/ym+C4tedOkH+VNG7U6S+GTEuGds7aT72QDRokQf7eNx3DxvNV/OWJE5Kfqq+0tJk9amE7KZqogMDWCQxKTIDTWyBs8U4yTmlhXPCex5oYYX8KsYuQYIe0VLTg92VlS23rgnO3hspuuNwijkguou0NWEiLeJQSlshGN+/6+PTB2Zs6Y72m2PjlObNmTv/iqWecT3f8E+SCHZIhe1zlu7C0KTjhJRretvv9XfyV/SImgXNnxNlNUsUEZbioQqX4IuR0kg5twYj+Y0Ht9aBY+j2p3lRncqKA2w1u211wuw1fSFJfpYB8wRjowJvY9xJuXv0xPnDOtFXo0mz+m/FTFXjDNt2L2uRDyIO8kZ+0rf9tbYCfUd55ef4LLwjM+SA2ig1GxPNJTTQpkERxYhOVS5HVAQQ9hAAqKK2Ss5omR2qnORzp6K1HIJ+NoBtl8kXyr9+riPqA/KJjTlZ6mt/rcuA0hY1FhAWt01QxFLUlIxDB4SKkYZUzRaRDsosOO/hdOqQ4xw+ZM43SMc27jp1svD5atatg13iTs2b6xQszavig1b8KPTTwleUPTD0kpB0TFNwfnueZtjx28cp15fymD/gavuEDUQcrUI+rkXM+0ZMwDJfZSsSYU6aAdUrL9GTKPmypDrYQQQ45mnl1eP2BrTVv7eRX9z2+gH+D7FCq58/47HC8nI6F9tvq4rvVw3zqk3j6IcvR4Ca0Jc5jnaJ5rUcxadMos45cP3feEuKPxpaLExaksvjRo00K69l0UM4TzV/ykbBZ7usixdGeOihMQ+mlpepdFmSGcMKoxkfUQwdhf8QjmN3GXKpLGtJbDGXmFgbMTunVVqDFv054ODKgAFJXCru3H7UfZH3Gs7lND69Ya+E3B20nihOzOdMKfgNYQf3buVbpyy88LaKC56EL/yW8Cn8bzqeoh2P1sJc/Gp+GNtpwM2d717+Y7REe9fCdAqlvS3CWXY+8ziGPvOd3YZMFi89+UWO0Cv0S7tl0VZFbpUXbSX/NQ4xJ99aHUjhwq5xMT1aO15Np19vnt5GgICc31AF8EcGOQOQnHNENJemNEVP5FdwoSUlgBa9Nu1WoFa2tWb+RX32j/AmVVkL7+pfju5QBI5/q5Pl9xvwZ15+bfuqj+FDxYMOv4tusWFg1xpJCSne4oC0Uj8h+lRjQkTyMWnEEZBzEOosFWp9URJNxMYWkiAjM4f3nIkDflRJJZfQ5EX2W7BbuIJfv8hMZPtTSt5Wob4lYQXfN04G75+lwVlamnKdxkM5sHadzwkHRv4NsJbYmHucXIQPPCHbw87PPzWsmC+aCQjvw2/wEdMKOoUI+v8C/31fPV+7YLfowH8mWsaE4U4bI2GiiW/YK8VqJedGRJuZaC5vWFiDkyANWMzbubdV3vSGacTj7nmYss1pgIiW7sautGZ8+MOvNzprGv7GBS9WxGe86xs+hEqEe0Uz+PT89fkyHNbyK/e3Vce4pBYcw4psw7U8N2/ZY+aUc8+sn483EJrpssk04gQxMiybiXyBXqBjbxAtuKVJitsAKa7nB8bfc6lgZYC1XOEn4ADteOFcemSJt6aZIWJ9sdMYKi6F50VF9u4f6FTwxR+lVUdPF/X6HWWM6O685t2yK/134OgDznYG+diQdo9lp7XQNrbTdtQRMwTfTnp2fbabdywR+ReZ5GY13oTjLiWarW6d/f6CDwjJ486ULf8/9P9+kRXOnjZz87caRN87uu9b+dtLYxydMKBu98EBNf+i17g/LVmWXRXtFC4p9XYfWjn3t7V8vb9f3oUivrkXudkWlNbL2U5u/pSvVHuhDn2gvD5LHC0TDGVuhyotCEmklM3mhaaRab5lu3C484ifYbfLKwgc+mzV2GuHCSFHEF/GFrXFTgzfmL65bParhyJFefULFk91L6uhzH3L+YfzjwSWO+kzpw0Ixy7CeiHvpDoc8NpgzjEeWo6xPWm2dzLFmW1Zlz8TSrTavUcyaxdxle8yaBdkEpTYHQjmFYTwYChdh9d7HF4jB5B9Jao+3sV5Zz/hv+aVtdbRfbOfLk1/p/+yM44fpNvTLhloyBf265zxj/PN5xuMP9AZPiJ7lDXwB5O129kjofQhycOfXu/0572M6TsZZjs6+h/vZxR2K3A8ET+UNJbFCUiotKuKqndjxWCN52HKo8ZVDnHdSErhG8w/RzY0fxR87QkhzszmjqufdOSQNRV8nBTDprvXbresRclKuS27K9Txr3dxH+ijXO1vrK+SZCZ+q3eSdkS7YLC5A773wJeK+lzHRj92GS9VS8z3ilgPxP8ImNr1WqhwRt0lqYVOAXZV4ELaBXlRvY5zd3rerVDEzL2o2Sd7+CrBdQoISqFDyF/GjuRU7PG4JO4ShMBIQbIMrX3H+m+OscQPbAMXt9+1rjx+PxL1HqOdxeAxG2yOFRSeQuywWTZFYNzxuRTZyD0I7MgaUb2UbMiCYz68T8v/+osLYAHicnVXNbhs3EB7JSvyTxAWKnIK0YJsWcABrbSkOECQnO4kTAw4cWPlBgQIFtcvVMl4tF0tKqnNr+xztAxRFn6PnXnrqI/TUPEA/zlKy4qguUC9W/pacGX4cfjMkok8bBTWo/ntFvwbcoI8aXwbcpOXG44CX6Ebjh4BbdL3xW8CX6FZzan+ZPm6agJfp7dK3Aa/Q9dYnAa/Seut5wGsNvXwn4Ct0c+WPgK9StHoc8DW6u/pXwOt0Y+0rMGm0VvH1C7PyuIG9XAq4SeuNuwEv0U7jWcAt2mj8FPAl2m/8GfBl+qJ5EPAyvWt+H/AKbSz9HfAq3Wx1A15r/t76JuArdG/lx4Cv0tcr7wK+Rm9Wvwt4nXbWbtEj0jTA6/C+JUUJCbwS3xIoJkMlnVLFVhlGBW1g9Db+d2mbOngFPYGVwXwOf0EPgSt4+V/JcQ0VFNEaz1wcrQt0HFg8Ze9NoAP4x4hAj/RAO/1WJSKRTorYlKeVHmRObMS3RXe7sy2eGDPIlXhoqtJU0mlTRGsPz5t1xTFCPJVuUxwUMeIeglAfy84TFtTDV0EW07qv6mCiJwsMeIoDGmHDEj50rAajXALswjrGXIEN+GiC2nj/I/qujVWRqEq0xQcLXewqaA8jORY7T1HsmTzxxeNd7cyxiyR28O7QA3w5PClCjfDf4Dg0bOojGbPVPbx3cDj0SlXWR+5GnWjngXAulSNnMl0go+NOdC+6c3sx1SnRBTQ9y5rk4j1q3p9kmn7GJ3TI6T7BmAHxi5QiYKdY1xYzir8Sjupjv4RFj62es6cKG5d8cIJeLFjxCCum8I9Z41PLmGP7WqkjG+AsHP0bJLZiBgn7TfdmvZLnzktbIYWrZKKGsjoRJn1fnaJSA22dqjCoC/Ey6kXiuXQKyZdFIl7MHI/SVMeKB2NVOQlj4zLo6s2o0jbRsV/NRotUurhkz5Q5V06EzPmMjTkPz9jcf9vapefUWIln0jllvfEeDGxIfn2wu5yUIb78gU1YdzF+PZZ86AmH8y2iCJ59NA1x4cIi+MogngKPgW29G++zGUSR8q8Neh9g1M7KyXI+NItrnoXgY5UsklqYQ8w6to0xnuM5De1yiDzWq/ZDQ5xwe81me4f9Z5+z/M5yUUs6DfUqeLQENsx9mr02n5znr5iVR5LbdR8eOa9T88hYuJJlp4IMHbOdZikJu/IMSx5p02OWrG/SKmTyNZr74cKIdbbmy8ZylY85b2exC2ab8JiZZdZb5WGlesc5XyIns1NJWY119hKO1v6X/KacGxdWNcwowVOfc60oA98Rn1pd6rXW3QeZk5xfE/xK7vAucBnWpbsnLUoRhbtb6aHZFJNMx5mYSCsSZfWgwGT/VLxfCAKzEqVeFGaMMhqrTZR1WimLJjoQ1ndsqyqdhhDCZdL5xjBUrtKxzPNTXHbDEq593G4T7TK/usx/jmoW6BopmrTQw7IyY6bXtnGlVIF1ZCL7OtcOMTJZyRi9BA1Fx5Z7BVqEKGXRfjyqTKlA8vWTwzND0Kr7jDX5WFm2LpRKrO9TCbaYwwkL58ac+K2kpgK9xGXtOb6pKRxcjZBJgj0jUSYeDX0HQ1dxU3Iyrgzmylw6RBn6TpVxjZd0n7bwTPiJuNLn+1cculcU9LEFR+fK+1tbk8kkkqGJxehhEUht/f+wXiIli3m+QVUsCx9zCLlcuLQ7LVUQSWWjzA3z+vqrl532ydFcZ55WUQ9X2yFfQ2Wo/f2genEugu9z5+/9Dt/7uHTAx4t3xF3dn2Xv4FAclZDJPs5IBINNMb3xO1HnfLrqDqTx7Xjjlusn4mQNMH8EZoezNECEunQ2sjqPTDXYOto/pH8ASVqSkgAAeJxtyjmOwWEAQPHf9zcFlTiAThCxL0Ghs8W+xMwoFAql8zqBuIVQoPKSl7ziiby5+EbraRCJSUpJy8jKySsoKimrqKqpazzPto6unr6BoZGxiamZuYWllbWNrZ1ff/7tHdwDV7cQhVj4iS+O59P8VK4mXtGsfar5AJo/FjsAAAAAAQAB//8AD3icY2BkYGDgAWIBIGYCYhYIDQACOwAmAAAAAAEAAAAA4o4ZkwAAAADISWgmAAAAAOZZb2c=')format("woff");}.ff1{font-family:ff1;line-height:0.958496;font-style:normal;font-weight:normal;visibility:visible;}
@font-face{font-family:ff2;src:url('data:application/font-woff;base64,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')format("woff");}.ff2{font-family:ff2;line-height:0.935547;font-style:normal;font-weight:normal;visibility:visible;}
.m0{transform:matrix(0.375000,0.000000,0.000000,0.375000,0,0);-ms-transform:matrix(0.375000,0.000000,0.000000,0.375000,0,0);-webkit-transform:matrix(0.375000,0.000000,0.000000,0.375000,0,0);}
.v0{vertical-align:0.000000px;}
.ls0{letter-spacing:0.000000px;}
.sc_{text-shadow:none;}
.sc0{text-shadow:-0.015em 0 transparent,0 0.015em transparent,0.015em 0 transparent,0 -0.015em  transparent;}
@media screen and (-webkit-min-device-pixel-ratio:0){
.sc_{-webkit-text-stroke:0px transparent;}
.sc0{-webkit-text-stroke:0.015em transparent;text-shadow:none;}
}
.ws0{word-spacing:0.000000px;}
._0{margin-left:-2.960000px;}
._5{margin-left:-1.152000px;}
._f{width:1.824000px;}
._2{width:307.480000px;}
._b{width:359.664000px;}
._8{width:446.008000px;}
._6{width:459.672000px;}
._7{width:489.680000px;}
._3{width:597.040000px;}
._4{width:610.872000px;}
._c{width:651.480000px;}
._10{width:674.920000px;}
._1{width:749.920000px;}
._11{width:899.696000px;}
._9{width:1191.208000px;}
._d{width:1201.256000px;}
._e{width:1242.368000px;}
._a{width:1579.976000px;}
.fc0{color:rgb(0,0,0);}
.fs1{font-size:28.000000px;}
.fs2{font-size:32.000000px;}
.fs0{font-size:40.000000px;}
.fs3{font-size:56.000000px;}
.y0{bottom:0.000000px;}
.y1{bottom:0.042000px;}
.y21{bottom:90.166500px;}
.y20{bottom:130.741500px;}
.y1f{bottom:230.266500px;}
.y1e{bottom:298.291500px;}
.y1d{bottom:330.466500px;}
.y1c{bottom:365.716500px;}
.y1b{bottom:426.391500px;}
.y1a{bottom:458.041500px;}
.y19{bottom:471.841500px;}
.y18{bottom:508.291500px;}
.y17{bottom:542.791500px;}
.y16{bottom:577.291500px;}
.y15{bottom:621.091500px;}
.y14{bottom:655.591500px;}
.y13{bottom:699.391500px;}
.y12{bottom:733.891500px;}
.y11{bottom:779.566500px;}
.y10{bottom:793.366500px;}
.yf{bottom:816.166500px;}
.ye{bottom:829.966500px;}
.yd{bottom:852.766500px;}
.yb{bottom:859.666500px;}
.yc{bottom:866.566500px;}
.ya{bottom:895.441500px;}
.y8{bottom:924.316500px;}
.y9{bottom:931.216500px;}
.y7{bottom:938.116500px;}
.y6{bottom:960.916500px;}
.y5{bottom:990.016500px;}
.y4{bottom:1037.491500px;}
.y3{bottom:1078.516500px;}
.y2{bottom:1180.066500px;}
.h4{height:20.384766px;}
.h8{height:23.296875px;}
.h7{height:23.890625px;}
.h5{height:29.121094px;}
.h3{height:29.863281px;}
.h6{height:41.808594px;}
.h2{height:1262.791500px;}
.h0{height:1262.834646px;}
.h1{height:1263.000000px;}
.w2{width:892.912500px;}
.w0{width:892.955906px;}
.w1{width:893.250000px;}
.x0{left:0.000000px;}
.x3{left:89.325000px;}
.x2{left:142.725000px;}
.x4{left:201.825000px;}
.x1{left:211.725000px;}
.x8{left:267.825000px;}
.x5{left:323.175000px;}
.x7{left:399.600000px;}
.x9{left:475.950000px;}
.x6{left:557.100000px;}
.xa{left:641.925000px;}
.xb{left:804.075000px;}
@media print{
.v0{vertical-align:0.000000pt;}
.ls0{letter-spacing:0.000000pt;}
.ws0{word-spacing:0.000000pt;}
._0{margin-left:-2.631111pt;}
._5{margin-left:-1.024000pt;}
._f{width:1.621333pt;}
._2{width:273.315556pt;}
._b{width:319.701333pt;}
._8{width:396.451556pt;}
._6{width:408.597333pt;}
._7{width:435.271111pt;}
._3{width:530.702222pt;}
._4{width:542.997333pt;}
._c{width:579.093333pt;}
._10{width:599.928889pt;}
._1{width:666.595556pt;}
._11{width:799.729778pt;}
._9{width:1058.851556pt;}
._d{width:1067.783111pt;}
._e{width:1104.327111pt;}
._a{width:1404.423111pt;}
.fs1{font-size:24.888889pt;}
.fs2{font-size:28.444444pt;}
.fs0{font-size:35.555556pt;}
.fs3{font-size:49.777778pt;}
.y0{bottom:0.000000pt;}
.y1{bottom:0.037333pt;}
.y21{bottom:80.148000pt;}
.y20{bottom:116.214667pt;}
.y1f{bottom:204.681333pt;}
.y1e{bottom:265.148000pt;}
.y1d{bottom:293.748000pt;}
.y1c{bottom:325.081333pt;}
.y1b{bottom:379.014667pt;}
.y1a{bottom:407.148000pt;}
.y19{bottom:419.414667pt;}
.y18{bottom:451.814667pt;}
.y17{bottom:482.481333pt;}
.y16{bottom:513.148000pt;}
.y15{bottom:552.081333pt;}
.y14{bottom:582.748000pt;}
.y13{bottom:621.681333pt;}
.y12{bottom:652.348000pt;}
.y11{bottom:692.948000pt;}
.y10{bottom:705.214667pt;}
.yf{bottom:725.481333pt;}
.ye{bottom:737.748000pt;}
.yd{bottom:758.014667pt;}
.yb{bottom:764.148000pt;}
.yc{bottom:770.281333pt;}
.ya{bottom:795.948000pt;}
.y8{bottom:821.614667pt;}
.y9{bottom:827.748000pt;}
.y7{bottom:833.881333pt;}
.y6{bottom:854.148000pt;}
.y5{bottom:880.014667pt;}
.y4{bottom:922.214667pt;}
.y3{bottom:958.681333pt;}
.y2{bottom:1048.948000pt;}
.h4{height:18.119792pt;}
.h8{height:20.708333pt;}
.h7{height:21.236111pt;}
.h5{height:25.885417pt;}
.h3{height:26.545139pt;}
.h6{height:37.163194pt;}
.h2{height:1122.481333pt;}
.h0{height:1122.519685pt;}
.h1{height:1122.666667pt;}
.w2{width:793.700000pt;}
.w0{width:793.738583pt;}
.w1{width:794.000000pt;}
.x0{left:0.000000pt;}
.x3{left:79.400000pt;}
.x2{left:126.866667pt;}
.x4{left:179.400000pt;}
.x1{left:188.200000pt;}
.x8{left:238.066667pt;}
.x5{left:287.266667pt;}
.x7{left:355.200000pt;}
.x9{left:423.066667pt;}
.x6{left:495.200000pt;}
.xa{left:570.600000pt;}
.xb{left:714.733333pt;}
}
</style>
<script>
/*
 Copyright 2012 Mozilla Foundation 
 Copyright 2013 Lu Wang <coolwanglu@gmail.com>
 Apachine License Version 2.0 
*/
(function(){function b(a,b,e,f){var c=(a.className||"").split(/\s+/g);""===c[0]&&c.shift();var d=c.indexOf(b);0>d&&e&&c.push(b);0<=d&&f&&c.splice(d,1);a.className=c.join(" ");return 0<=d}if(!("classList"in document.createElement("div"))){var e={add:function(a){b(this.element,a,!0,!1)},contains:function(a){return b(this.element,a,!1,!1)},remove:function(a){b(this.element,a,!1,!0)},toggle:function(a){b(this.element,a,!0,!0)}};Object.defineProperty(HTMLElement.prototype,"classList",{get:function(){if(this._classList)return this._classList;
var a=Object.create(e,{element:{value:this,writable:!1,enumerable:!0}});Object.defineProperty(this,"_classList",{value:a,writable:!1,enumerable:!1});return a},enumerable:!0})}})();
</script>
<script>
(function(){/*
 pdf2htmlEX.js: Core UI functions for pdf2htmlEX 
 Copyright 2012,2013 Lu Wang <coolwanglu@gmail.com> and other contributors 
 https://github.com/pdf2htmlEX/pdf2htmlEX/blob/master/share/LICENSE 
*/
var pdf2htmlEX=window.pdf2htmlEX=window.pdf2htmlEX||{},CSS_CLASS_NAMES={page_frame:"pf",page_content_box:"pc",page_data:"pi",background_image:"bi",link:"l",input_radio:"ir",__dummy__:"no comma"},DEFAULT_CONFIG={container_id:"page-container",sidebar_id:"sidebar",outline_id:"outline",loading_indicator_cls:"loading-indicator",preload_pages:3,render_timeout:100,scale_step:0.9,key_handler:!0,hashchange_handler:!0,view_history_handler:!0,__dummy__:"no comma"},EPS=1E-6;
function invert(a){var b=a[0]*a[3]-a[1]*a[2];return[a[3]/b,-a[1]/b,-a[2]/b,a[0]/b,(a[2]*a[5]-a[3]*a[4])/b,(a[1]*a[4]-a[0]*a[5])/b]}function transform(a,b){return[a[0]*b[0]+a[2]*b[1]+a[4],a[1]*b[0]+a[3]*b[1]+a[5]]}function get_page_number(a){return parseInt(a.getAttribute("data-page-no"),16)}function disable_dragstart(a){for(var b=0,c=a.length;b<c;++b)a[b].addEventListener("dragstart",function(){return!1},!1)}
function clone_and_extend_objs(a){for(var b={},c=0,e=arguments.length;c<e;++c){var h=arguments[c],d;for(d in h)h.hasOwnProperty(d)&&(b[d]=h[d])}return b}
function Page(a){if(a){this.shown=this.loaded=!1;this.page=a;this.num=get_page_number(a);this.original_height=a.clientHeight;this.original_width=a.clientWidth;var b=a.getElementsByClassName(CSS_CLASS_NAMES.page_content_box)[0];b&&(this.content_box=b,this.original_scale=this.cur_scale=this.original_height/b.clientHeight,this.page_data=JSON.parse(a.getElementsByClassName(CSS_CLASS_NAMES.page_data)[0].getAttribute("data-data")),this.ctm=this.page_data.ctm,this.ictm=invert(this.ctm),this.loaded=!0)}}
Page.prototype={hide:function(){this.loaded&&this.shown&&(this.content_box.classList.remove("opened"),this.shown=!1)},show:function(){this.loaded&&!this.shown&&(this.content_box.classList.add("opened"),this.shown=!0)},rescale:function(a){this.cur_scale=0===a?this.original_scale:a;this.loaded&&(a=this.content_box.style,a.msTransform=a.webkitTransform=a.transform="scale("+this.cur_scale.toFixed(3)+")");a=this.page.style;a.height=this.original_height*this.cur_scale+"px";a.width=this.original_width*this.cur_scale+
"px"},view_position:function(){var a=this.page,b=a.parentNode;return[b.scrollLeft-a.offsetLeft-a.clientLeft,b.scrollTop-a.offsetTop-a.clientTop]},height:function(){return this.page.clientHeight},width:function(){return this.page.clientWidth}};function Viewer(a){this.config=clone_and_extend_objs(DEFAULT_CONFIG,0<arguments.length?a:{});this.pages_loading=[];this.init_before_loading_content();var b=this;document.addEventListener("DOMContentLoaded",function(){b.init_after_loading_content()},!1)}
Viewer.prototype={scale:1,cur_page_idx:0,first_page_idx:0,init_before_loading_content:function(){this.pre_hide_pages()},initialize_radio_button:function(){for(var a=document.getElementsByClassName(CSS_CLASS_NAMES.input_radio),b=0;b<a.length;b++)a[b].addEventListener("click",function(){this.classList.toggle("checked")})},init_after_loading_content:function(){this.sidebar=document.getElementById(this.config.sidebar_id);this.outline=document.getElementById(this.config.outline_id);this.container=document.getElementById(this.config.container_id);
this.loading_indicator=document.getElementsByClassName(this.config.loading_indicator_cls)[0];for(var a=!0,b=this.outline.childNodes,c=0,e=b.length;c<e;++c)if("ul"===b[c].nodeName.toLowerCase()){a=!1;break}a||this.sidebar.classList.add("opened");this.find_pages();if(0!=this.pages.length){disable_dragstart(document.getElementsByClassName(CSS_CLASS_NAMES.background_image));this.config.key_handler&&this.register_key_handler();var h=this;this.config.hashchange_handler&&window.addEventListener("hashchange",
function(a){h.navigate_to_dest(document.location.hash.substring(1))},!1);this.config.view_history_handler&&window.addEventListener("popstate",function(a){a.state&&h.navigate_to_dest(a.state)},!1);this.container.addEventListener("scroll",function(){h.update_page_idx();h.schedule_render(!0)},!1);[this.outline].concat(Array.from(this.container.querySelectorAll("a.l"))).forEach(function(a){a.addEventListener("click",h.link_handler.bind(h),!1)});this.initialize_radio_button();this.render()}},find_pages:function(){for(var a=
[],b={},c=this.container.childNodes,e=0,h=c.length;e<h;++e){var d=c[e];d.nodeType===Node.ELEMENT_NODE&&d.classList.contains(CSS_CLASS_NAMES.page_frame)&&(d=new Page(d),a.push(d),b[d.num]=a.length-1)}this.pages=a;this.page_map=b},load_page:function(a,b,c){var e=this.pages;if(!(a>=e.length||(e=e[a],e.loaded||this.pages_loading[a]))){var e=e.page,h=e.getAttribute("data-page-url");if(h){this.pages_loading[a]=!0;var d=e.getElementsByClassName(this.config.loading_indicator_cls)[0];"undefined"===typeof d&&
(d=this.loading_indicator.cloneNode(!0),d.classList.add("active"),e.appendChild(d));var f=this,g=new XMLHttpRequest;g.open("GET",h,!0);g.onload=function(){if(200===g.status||0===g.status){var b=document.createElement("div");b.innerHTML=g.responseText;for(var d=null,b=b.childNodes,e=0,h=b.length;e<h;++e){var p=b[e];if(p.nodeType===Node.ELEMENT_NODE&&p.classList.contains(CSS_CLASS_NAMES.page_frame)){d=p;break}}b=f.pages[a];f.container.replaceChild(d,b.page);b=new Page(d);f.pages[a]=b;b.hide();b.rescale(f.scale);
disable_dragstart(d.getElementsByClassName(CSS_CLASS_NAMES.background_image));f.schedule_render(!1);c&&c(b)}delete f.pages_loading[a]};g.send(null)}void 0===b&&(b=this.config.preload_pages);0<--b&&(f=this,setTimeout(function(){f.load_page(a+1,b)},0))}},pre_hide_pages:function(){var a="@media screen{."+CSS_CLASS_NAMES.page_content_box+"{display:none;}}",b=document.createElement("style");b.styleSheet?b.styleSheet.cssText=a:b.appendChild(document.createTextNode(a));document.head.appendChild(b)},render:function(){for(var a=
this.container,b=a.scrollTop,c=a.clientHeight,a=b-c,b=b+c+c,c=this.pages,e=0,h=c.length;e<h;++e){var d=c[e],f=d.page,g=f.offsetTop+f.clientTop,f=g+f.clientHeight;g<=b&&f>=a?d.loaded?d.show():this.load_page(e):d.hide()}},update_page_idx:function(){var a=this.pages,b=a.length;if(!(2>b)){for(var c=this.container,e=c.scrollTop,c=e+c.clientHeight,h=-1,d=b,f=d-h;1<f;){var g=h+Math.floor(f/2),f=a[g].page;f.offsetTop+f.clientTop+f.clientHeight>=e?d=g:h=g;f=d-h}this.first_page_idx=d;for(var g=h=this.cur_page_idx,
k=0;d<b;++d){var f=a[d].page,l=f.offsetTop+f.clientTop,f=f.clientHeight;if(l>c)break;f=(Math.min(c,l+f)-Math.max(e,l))/f;if(d===h&&Math.abs(f-1)<=EPS){g=h;break}f>k&&(k=f,g=d)}this.cur_page_idx=g}},schedule_render:function(a){if(void 0!==this.render_timer){if(!a)return;clearTimeout(this.render_timer)}var b=this;this.render_timer=setTimeout(function(){delete b.render_timer;b.render()},this.config.render_timeout)},register_key_handler:function(){var a=this;window.addEventListener("DOMMouseScroll",function(b){if(b.ctrlKey){b.preventDefault();
var c=a.container,e=c.getBoundingClientRect(),c=[b.clientX-e.left-c.clientLeft,b.clientY-e.top-c.clientTop];a.rescale(Math.pow(a.config.scale_step,b.detail),!0,c)}},!1);window.addEventListener("keydown",function(b){var c=!1,e=b.ctrlKey||b.metaKey,h=b.altKey;switch(b.keyCode){case 61:case 107:case 187:e&&(a.rescale(1/a.config.scale_step,!0),c=!0);break;case 173:case 109:case 189:e&&(a.rescale(a.config.scale_step,!0),c=!0);break;case 48:e&&(a.rescale(0,!1),c=!0);break;case 33:h?a.scroll_to(a.cur_page_idx-
1):a.container.scrollTop-=a.container.clientHeight;c=!0;break;case 34:h?a.scroll_to(a.cur_page_idx+1):a.container.scrollTop+=a.container.clientHeight;c=!0;break;case 35:a.container.scrollTop=a.container.scrollHeight;c=!0;break;case 36:a.container.scrollTop=0,c=!0}c&&b.preventDefault()},!1)},rescale:function(a,b,c){var e=this.scale;this.scale=a=0===a?1:b?e*a:a;c||(c=[0,0]);b=this.container;c[0]+=b.scrollLeft;c[1]+=b.scrollTop;for(var h=this.pages,d=h.length,f=this.first_page_idx;f<d;++f){var g=h[f].page;
if(g.offsetTop+g.clientTop>=c[1])break}g=f-1;0>g&&(g=0);var g=h[g].page,k=g.clientWidth,f=g.clientHeight,l=g.offsetLeft+g.clientLeft,m=c[0]-l;0>m?m=0:m>k&&(m=k);k=g.offsetTop+g.clientTop;c=c[1]-k;0>c?c=0:c>f&&(c=f);for(f=0;f<d;++f)h[f].rescale(a);b.scrollLeft+=m/e*a+g.offsetLeft+g.clientLeft-m-l;b.scrollTop+=c/e*a+g.offsetTop+g.clientTop-c-k;this.schedule_render(!0)},fit_width:function(){var a=this.cur_page_idx;this.rescale(this.container.clientWidth/this.pages[a].width(),!0);this.scroll_to(a)},fit_height:function(){var a=
this.cur_page_idx;this.rescale(this.container.clientHeight/this.pages[a].height(),!0);this.scroll_to(a)},get_containing_page:function(a){for(;a;){if(a.nodeType===Node.ELEMENT_NODE&&a.classList.contains(CSS_CLASS_NAMES.page_frame)){a=get_page_number(a);var b=this.page_map;return a in b?this.pages[b[a]]:null}a=a.parentNode}return null},link_handler:function(a){var b=a.target,c=b.getAttribute("data-dest-detail");c||(b=a.currentTarget,c=b.getAttribute("data-dest-detail"));if(c){if(this.config.view_history_handler)try{var e=
this.get_current_view_hash();window.history.replaceState(e,"","#"+e);window.history.pushState(c,"","#"+c)}catch(h){}this.navigate_to_dest(c,this.get_containing_page(b));a.preventDefault()}},navigate_to_dest:function(a,b){try{var c=JSON.parse(a)}catch(e){return}if(c instanceof Array){var h=c[0],d=this.page_map;if(h in d){for(var f=d[h],h=this.pages[f],d=2,g=c.length;d<g;++d){var k=c[d];if(null!==k&&"number"!==typeof k)return}for(;6>c.length;)c.push(null);var g=b||this.pages[this.cur_page_idx],d=g.view_position(),
d=transform(g.ictm,[d[0],g.height()-d[1]]),g=this.scale,l=[0,0],m=!0,k=!1,n=this.scale;switch(c[1]){case "XYZ":l=[null===c[2]?d[0]:c[2]*n,null===c[3]?d[1]:c[3]*n];g=c[4];if(null===g||0===g)g=this.scale;k=!0;break;case "Fit":case "FitB":l=[0,0];k=!0;break;case "FitH":case "FitBH":l=[0,null===c[2]?d[1]:c[2]*n];k=!0;break;case "FitV":case "FitBV":l=[null===c[2]?d[0]:c[2]*n,0];k=!0;break;case "FitR":l=[c[2]*n,c[5]*n],m=!1,k=!0}if(k){this.rescale(g,!1);var p=this,c=function(a){l=transform(a.ctm,l);m&&
(l[1]=a.height()-l[1]);p.scroll_to(f,l)};h.loaded?c(h):(this.load_page(f,void 0,c),this.scroll_to(f))}}}},scroll_to:function(a,b){var c=this.pages;if(!(0>a||a>=c.length)){c=c[a].view_position();void 0===b&&(b=[0,0]);var e=this.container;e.scrollLeft+=b[0]-c[0];e.scrollTop+=b[1]-c[1]}},get_current_view_hash:function(){var a=[],b=this.pages[this.cur_page_idx];a.push(b.num);a.push("XYZ");var c=b.view_position(),c=transform(b.ictm,[c[0],b.height()-c[1]]);a.push(c[0]/this.scale);a.push(c[1]/this.scale);
a.push(this.scale);return JSON.stringify(a)}};pdf2htmlEX.Viewer=Viewer;})();
</script>
<script>
try{
pdf2htmlEX.defaultViewer = new pdf2htmlEX.Viewer({});
}catch(e){}
</script>
<title></title>
<style>
    html,
    body {
        margin: 0 !important;
        padding: 0 !important;
        overflow: hidden !important;
        background: #9e9e9e !important;
    }

    #sidebar {
        display: none !important;
    }

    #page-container {
        left: 0 !important;
        right: 0 !important;
        top: 0 !important;
        bottom: 0 !important;
        overflow: auto !important;
    }

    .contract-dynamic-layer {
        position: absolute;
        left: 0;
        top: 0;
        width: 893.25px;
        height: 1263px;
        z-index: 50;
        pointer-events: none;
        color: #000;
        font-family: Arial, Helvetica, sans-serif;
    }

    .contract-dynamic-value {
        position: absolute;
        display: block;
        color: #000;
        font-family: Arial, Helvetica, sans-serif;
        font-size: 16px;
        line-height: 1.1;
        font-weight: 600;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        box-sizing: border-box;
        padding: 1px 4px;
    }

    .contract-dynamic-value.center {
        text-align: center;
    }

    .contract-dynamic-value.small {
        font-size: 14px;
        font-weight: 600;
    }

    .contract-dynamic-value.multiline {
        white-space: normal;
        line-height: 1.2;
        overflow: hidden;
    }

    /*
        Ove koordinate su izvučene prema stvarnim sivim poljima iz originalnog obrasca.
        Originalna slika obrasca je 1191 x 1684 px.
        U pdf2htmlEX prikazu renderira se kao 893.25 x 1263 px.
        Faktor skaliranja je 0.75.
    */

    /* Gornji blok: prodavatelj i kupac */
    .field-seller-name {
        left: 100px;
        top: 112px;
        width: 332px;
        height: 22px;
    }

    .field-seller-address {
        left: 100px;
        top: 140px;
        width: 332px;
        height: 22px;
    }

    .field-buyer-name {
        left: 462px;
        top: 112px;
        width: 332px;
        height: 22px;
    }

    .field-buyer-address {
        left: 462px;
        top: 140px;
        width: 332px;
        height: 22px;
    }

    /* Mjesto i datum */
    .field-place {
        left: 216px;
        top: 210px;
        width: 220px;
        height: 22px;
    }

    .field-contract-date {
        left: 498px;
        top: 210px;
        width: 225px;
        height: 22px;
    }

    /* Vozilo - prvi red */
    .field-registration-number {
        left: 171px;
        top: 317px;
        width: 142px;
        height: 22px;
    }

    .field-vehicle-type {
        left: 401px;
        top: 317px;
        width: 146px;
        height: 22px;
    }

    .field-vehicle-brand {
        left: 644px;
        top: 317px;
        width: 158px;
        height: 22px;
    }

    /* Vozilo - drugi red */
    .field-vehicle-tip {
        left: 171px;
        top: 353px;
        width: 142px;
        height: 22px;
    }

    .field-vehicle-model {
        left: 401px;
        top: 353px;
        width: 146px;
        height: 22px;
    }

    .field-vehicle-color {
        left: 644px;
        top: 353px;
        width: 158px;
        height: 22px;
    }

    /* Vozilo - treći red */
    .field-vin {
        left: 171px;
        top: 388px;
        width: 375px;
        height: 22px;
    }

    .field-body-shape {
        left: 644px;
        top: 388px;
        width: 158px;
        height: 22px;
    }

    /* Vozilo - četvrti red */
    .field-manufacturer-country {
        left: 171px;
        top: 425px;
        width: 218px;
        height: 22px;
    }

    .field-production-year {
        left: 477px;
        top: 425px;
        width: 70px;
        height: 22px;
    }

    .field-vehicle-purpose {
        left: 644px;
        top: 425px;
        width: 158px;
        height: 22px;
    }

    /* Vozilo - peti red */
    .field-first-registration-date {
        left: 171px;
        top: 462px;
        width: 88px;
        height: 22px;
    }

    .field-engine-type {
        left: 325px;
        top: 462px;
        width: 141px;
        height: 22px;
    }

    .field-engine-power-kw {
        left: 559px;
        top: 462px;
        width: 75px;
        height: 22px;
    }

    .field-engine-displacement-cc {
        left: 733px;
        top: 462px;
        width: 68px;
        height: 22px;
    }

    /* Cijena */
    .field-price-amount {
        left: 313px;
        top: 513px;
        width: 442px;
        height: 22px;
    }

    .field-price-words {
        left: 166px;
        top: 548px;
        width: 589px;
        height: 22px;
    }

    /* Isplata */
    .field-paid-date {
        left: 295px;
        top: 592px;
        width: 132px;
        height: 22px;
    }

    .field-paid-amount {
        left: 515px;
        top: 592px;
        width: 240px;
        height: 22px;
    }

    .field-paid-words {
        left: 166px;
        top: 626px;
        width: 589px;
        height: 22px;
    }

    /* Ostatak cijene */
    .field-remaining-amount {
        left: 309px;
        top: 670px;
        width: 447px;
        height: 22px;
    }

    .field-remaining-words {
        left: 166px;
        top: 704px;
        width: 589px;
        height: 22px;
    }

    .field-remaining-due-date {
        left: 286px;
        top: 739px;
        width: 462px;
        height: 22px;
    }

    /* Predane stvari */
    .field-included-items {
        left: 91px;
        top: 846px;
        width: 710px;
        height: 22px;
    }

    /* Troškovi */
    .field-costs-paid-by {
        left: 330px;
        top: 881px;
        width: 470px;
        height: 22px;
    }

    /* Sud */
    .field-court-place {
        left: 656px;
        top: 916px;
        width: 145px;
        height: 22px;
    }

    /* Napomena */
    .field-note {
        left: 91px;
        top: 975px;
        width: 710px;
        height: 105px;
    }

    /* OIB potpisi */
    .field-seller-oib {
        left: 210px;
        top: 1158px;
        width: 230px;
        height: 22px;
    }

    .field-buyer-oib {
        left: 571px;
        top: 1158px;
        width: 230px;
        height: 22px;
    }
</style>
</head>
<body>
<div id="sidebar">
<div id="outline">
</div>
</div>
<div id="page-container">
<div id="pf1" class="pf w0 h0" data-page-no="1">
    <div class="contract-dynamic-layer">
    <span class="contract-dynamic-value field-seller-name center" data-preview="seller_name"></span>
        <span class="contract-dynamic-value field-seller-address center small" data-preview="seller_address"></span>

        <span class="contract-dynamic-value field-buyer-name center" data-preview="buyer_name"></span>
        <span class="contract-dynamic-value field-buyer-address center small" data-preview="buyer_address"></span>

        <span class="contract-dynamic-value field-place center" data-preview="place"></span>
        <span class="contract-dynamic-value field-contract-date center" data-preview="contract_date"></span>

        <span class="contract-dynamic-value field-registration-number center" data-preview="registration_number"></span>
        <span class="contract-dynamic-value field-vehicle-type center" data-preview="vehicle_type"></span>
        <span class="contract-dynamic-value field-vehicle-brand center" data-preview="vehicle_brand"></span>

        <span class="contract-dynamic-value field-vehicle-tip center" data-preview="vehicle_tip"></span>
        <span class="contract-dynamic-value field-vehicle-model center" data-preview="vehicle_model"></span>
        <span class="contract-dynamic-value field-vehicle-color center" data-preview="vehicle_color"></span>

        <span class="contract-dynamic-value field-vin center" data-preview="vin"></span>
        <span class="contract-dynamic-value field-body-shape center" data-preview="body_shape"></span>

        <span class="contract-dynamic-value field-manufacturer-country center small" data-preview="manufacturer_country"></span>
        <span class="contract-dynamic-value field-production-year center" data-preview="production_year"></span>
        <span class="contract-dynamic-value field-vehicle-purpose center" data-preview="vehicle_purpose"></span>

        <span class="contract-dynamic-value field-first-registration-date center small" data-preview="first_registration_date"></span>
        <span class="contract-dynamic-value field-engine-type center" data-preview="engine_type"></span>
        <span class="contract-dynamic-value field-engine-power-kw center" data-preview="engine_power_kw"></span>
        <span class="contract-dynamic-value field-engine-displacement-cc center" data-preview="engine_displacement_cc"></span>

        <span class="contract-dynamic-value field-price-amount center" data-preview="price_amount"></span>
        <span class="contract-dynamic-value field-price-words center" data-preview="price_words"></span>

        <span class="contract-dynamic-value field-paid-date center small" data-preview="paid_date"></span>
        <span class="contract-dynamic-value field-paid-amount center" data-preview="paid_amount"></span>
        <span class="contract-dynamic-value field-paid-words center" data-preview="paid_words"></span>

        <span class="contract-dynamic-value field-remaining-amount center" data-preview="remaining_amount"></span>
        <span class="contract-dynamic-value field-remaining-words center" data-preview="remaining_words"></span>
        <span class="contract-dynamic-value field-remaining-due-date center small" data-preview="remaining_due_date"></span>

        <span class="contract-dynamic-value field-included-items center" data-preview="included_items"></span>
        <span class="contract-dynamic-value field-costs-paid-by center" data-preview="costs_paid_by"></span>
        <span class="contract-dynamic-value field-court-place center" data-preview="court_place"></span>

        <span class="contract-dynamic-value field-note multiline small" data-preview="note"></span>

        <span class="contract-dynamic-value field-seller-oib center" data-preview="seller_oib"></span>
        <span class="contract-dynamic-value field-buyer-oib center" data-preview="buyer_oib"></span>
    </div>
    <div class="pc pc1 w0 h0"><img class="bi x0 y0 w1 h1" alt="" src="data:image/png;base64,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"/><div class="c x0 y1 w2 h2"><div class="t m0 x1 h3 y2 ff1 fs0 fc0 sc0 ls0 ws0">PRODA<span class="_ _0"></span>V<span class="_ _0"></span>A<span class="_ _0"></span>TELJ<span class="_ _1"> </span>KUP<span class="_ _0"></span>AC</div><div class="t m0 x2 h4 y3 ff2 fs1 fc0 sc0 ls0 ws0">(Ime i prezime fizičke ili naziv pravne osobe i adresa)<span class="_ _2"> </span>(Ime i prezime fizičke ili naziv pravne osobe i adresa)</div><div class="t m0 x3 h5 y4 ff2 fs2 fc0 sc0 ls0 ws0">zaključili su u (mjesto)<span class="fs0">  <span class="_ _3"> </span>  </span>(datum)<span class="fs0">  <span class="_ _4"> </span> </span>godine ovaj:</div><div class="t m0 x4 h6 y5 ff1 fs3 fc0 sc0 ls0 ws0">UGOVOR O KUPOPRODAJI MOTORNOG VOZILA</div><div class="t m0 x3 h7 y6 ff1 fs2 fc0 sc0 ls0 ws0">Prodavatelj prodaje kupcu motorno vozilo:</div><div class="t m0 x3 h8 y7 ff2 fs2 fc0 sc0 ls0 ws0">Registarska </div><div class="t m0 x3 h8 y8 ff2 fs2 fc0 sc0 ls0 ws0">oznaka</div><div class="t m0 x5 h8 y9 ff2 fs2 fc0 sc0 ls0 ws0">V<span class="_ _5"></span>rsta vozila<span class="_ _6"> </span>Marka vozila</div><div class="t m0 x3 h8 ya ff2 fs2 fc0 sc0 ls0 ws0">T<span class="_ _5"></span>ip vozila<span class="_ _7"> </span>Model vozila<span class="_ _8"> </span>Boja vozila</div><div class="t m0 x3 h8 yb ff2 fs2 fc0 sc0 ls0 ws0">Broj šasije</div><div class="t m0 x6 h8 yc ff2 fs2 fc0 sc0 ls0 ws0">Oblik </div><div class="t m0 x6 h8 yd ff2 fs2 fc0 sc0 ls0 ws0">karoserije</div><div class="t m0 x3 h8 ye ff2 fs2 fc0 sc0 ls0 ws0">Država proiz. </div><div class="t m0 x3 h8 yf ff2 fs2 fc0 sc0 ls0 ws0">i proizvođač</div><div class="t m0 x7 h8 ye ff2 fs2 fc0 sc0 ls0 ws0">Godina </div><div class="t m0 x7 h8 yf ff2 fs2 fc0 sc0 ls0 ws0">proizvodnje</div><div class="t m0 x6 h8 ye ff2 fs2 fc0 sc0 ls0 ws0">Osnovna </div><div class="t m0 x6 h8 yf ff2 fs2 fc0 sc0 ls0 ws0">namjena</div><div class="t m0 x3 h8 y10 ff2 fs2 fc0 sc0 ls0 ws0">Datum prve </div><div class="t m0 x3 h8 y11 ff2 fs2 fc0 sc0 ls0 ws0">registracije</div><div class="t m0 x8 h8 y10 ff2 fs2 fc0 sc0 ls0 ws0">V<span class="_ _5"></span>rsta </div><div class="t m0 x8 h8 y11 ff2 fs2 fc0 sc0 ls0 ws0">mortora</div><div class="t m0 x9 h8 y10 ff2 fs2 fc0 sc0 ls0 ws0">Snaga </div><div class="t m0 x9 h8 y11 ff2 fs2 fc0 sc0 ls0 ws0">motora u kW</div><div class="t m0 xa h8 y10 ff2 fs2 fc0 sc0 ls0 ws0">Rad. Obujam </div><div class="t m0 xa h8 y11 ff2 fs2 fc0 sc0 ls0 ws0">motora u cm3</div><div class="t m0 x3 h7 y12 ff1 fs2 fc0 sc0 ls0 ws0">Prodajna cijena ugovorena je u iznosu<span class="ff2"> <span class="_ _9"> </span> EUR;</span></div><div class="t m0 x3 h8 y13 ff2 fs2 fc0 sc0 ls0 ws0">iznos riječima <span class="_ _a"> </span> eura.</div><div class="t m0 x3 h8 y14 ff2 fs2 fc0 sc0 ls0 ws0">Kupac je prodavatelju isplatio (datum) <span class="_ _b"> </span> godine; (iznos) <span class="_ _c"> </span> EUR</div><div class="t m0 x3 h8 y15 ff2 fs2 fc0 sc0 ls0 ws0">iznos riječima <span class="_ _a"> </span> eura,</div><div class="t m0 x3 h8 y16 ff2 fs2 fc0 sc0 ls0 ws0">a ostatak od prodajne cijene u iznosu od <span class="_ _d"> </span> EUR;</div><div class="t m0 x3 h8 y17 ff2 fs2 fc0 sc0 ls0 ws0">iznos riječima <span class="_ _a"> </span> eura</div><div class="t m0 x3 h8 y18 ff2 fs2 fc0 sc0 ls0 ws0">kupac se obvezuje platiti do (datum) <span class="_ _e"> </span> godine.</div><div class="t m0 x3 h7 y19 ff1 fs2 fc0 sc0 ls0 ws0">Prodavatelj jamči da je vozilo njegovo vlasništvo i da nije opterećeno ovrhom, zabilježbom ili drugim teretom. Kupac je </div><div class="t m0 x3 h7 y1a ff1 fs2 fc0 sc0 ls0 ws0">pregledao vozilo i nema prigovora u svezi s kvalitetom i prodajnom cijenom.</div><div class="t m0 x3 h7 y1b ff1 fs2 fc0 sc0 ls0 ws0">Uz motorno vozilo, prodavatelj je kupcu predao sljedeće stvari:</div><div class="t m0 x3 h7 y1c ff1 fs2 fc0 sc0 ls0 ws0">Upravnu pristojbu i ostale troškove snosi<span class="ff2"> </span></div><div class="t m0 x3 h7 y1d ff1 fs2 fc0 sc0 ls0 ws0">Prodavatelj i kupac prihvaćaju prava i obveze iz ovog ugovora, a u slučaju spora nadležan je sud u<span class="_ _f"></span><span class="ff2"> </span></div><div class="t m0 x3 h7 y1e ff1 fs2 fc0 sc0 ls0 ws0">Napomena:</div><div class="t m0 xb h8 y1f ff2 fs2 fc0 sc0 ls0 ws0"> </div><div class="t m0 x3 h3 y20 ff1 fs0 fc0 sc0 ls0 ws0">PRODA<span class="_ _0"></span>V<span class="_ _0"></span>A<span class="_ _0"></span>TELJ<span class="_ _10"> </span>KUP<span class="_ _0"></span>AC</div><div class="t m0 x3 h8 y21 ff2 fs2 fc0 sc0 ls0 ws0">OIB:<span class="_ _11"> </span>OIB:</div></div></div><div class="pi" data-data='{"ctm":[1.500000,0.000000,0.000000,1.500000,0.000000,0.000000]}'></div></div>
    
</div>
<div class="loading-indicator">
<img alt="" src="data:image/png;base64,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"/>
</div>

<script>
    window.addEventListener('message', function (event) {
        if (event.origin !== window.location.origin || event.source !== window.parent) {
            return;
        }

        if (! event.data || event.data.type !== 'contract-preview:update') {
            return;
        }

        const values = event.data.values || {};

        Object.keys(values).forEach(function (key) {
            const elements = document.querySelectorAll('[data-preview="' + key + '"]');

            elements.forEach(function (element) {
                element.textContent = values[key] || '';
            });
        });
    });
</script>
</body>
</html>
